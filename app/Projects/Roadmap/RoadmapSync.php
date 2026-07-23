<?php

namespace App\Projects\Roadmap;

use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RoadmapEvent;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class RoadmapSync
{
    public function __construct(
        private readonly Project $project
    ) {
    }

    public static function for(Project $project): self
    {
        return new self($project);
    }

    /**
     * The milestone keys (`M<N>`) a roadmap markdown declares, in document order.
     * The one authority on "which milestones does this roadmap still define" —
     * used by the redefine reconciler (M16-C) to spot milestones a revision
     * dropped. Same header grammar as the full parser.
     *
     * @return array<int, string>
     */
    public static function milestoneKeysIn(string $markdown): array
    {
        $keys = [];
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^##\s+(?:M(?:ilestone)?)\s*(\d+(?:[a-z]?)?)\s*[—:]\s*(.+)$/iu', $line, $m)) {
                $keys[] = 'M'.$m[1];
            }
        }

        return $keys;
    }

    public function sync(): void
    {
        $filePath = rtrim($this->project->repo_path ?? '', '/') . '/agents/ROADMAP.md';
        $content = null;
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        } else {
            $content = app(\App\Projects\Memory\MemoryStore::class)->read($this->project, 'roadmap.md');
        }

        if ($content === null || trim($content) === '') {
            return;
        }

        $parsed = $this->parse($content);

        DB::transaction(function () use ($parsed) {
            $this->syncMilestones($parsed['milestones']);
            $this->syncTasks($parsed['milestones']);
        });
    }

    public static function effectiveStatus(Task $task): string
    {
        $dbStatus = match ($task->status) {
            TaskStatus::Approved => 'done',
            TaskStatus::Building, TaskStatus::Testing, TaskStatus::Reviewing, TaskStatus::NeedsYou => 'ongoing',
            TaskStatus::Pending, TaskStatus::Failed => 'todo',
        };

        $declared = $task->declared_status ?? 'todo';

        $order = ['todo' => 0, 'ongoing' => 1, 'done' => 2];
        $dbVal = $order[$dbStatus] ?? 0;
        $declaredVal = $order[$declared] ?? 0;

        $maxVal = max($dbVal, $declaredVal);
        return array_search($maxVal, $order, true);
    }

    private function parse(string $content): array
    {
        $lines = explode("\n", $content);
        $milestones = [];
        $currentMilestone = null;
        $milestonePosition = 0;
        $summaryLines = [];

        foreach ($lines as $line) {
            // Tolerates both structured `## M<N> — <title>` and legacy `## Milestone <N>: <title>`
            if (preg_match('/^##\s+(?:M(?:ilestone)?)\s*(\d+(?:[a-z]?)?)\s*[—:]\s*(.+)$/iu', $line, $matches)) {
                if ($currentMilestone) {
                    $currentMilestone['summary'] = trim(implode("\n", $summaryLines));
                    $milestones[] = $currentMilestone;
                }
                $milestonePosition++;
                $currentMilestone = [
                    'key' => 'M' . $matches[1],
                    'title' => trim($matches[2]),
                    'summary' => '',
                    'position' => $milestonePosition,
                    'tasks' => [],
                ];
                $summaryLines = [];
            } elseif ($currentMilestone && preg_match('/^-\s+\[([ x~])\]\s+(T-\d+(?:[a-z]?)?)\s*—\s*(.+)$/iu', $line, $matches)) {
                $mark = $matches[1];
                $status = match ($mark) {
                    ' ' => 'todo',
                    '~' => 'ongoing',
                    'x' => 'done',
                    default => 'todo',
                };
                $currentMilestone['tasks'][] = [
                    'key' => $matches[2],
                    'title' => trim($matches[3]),
                    'declared_status' => $status,
                    'position' => count($currentMilestone['tasks']) + 1,
                ];
            } elseif ($currentMilestone && !preg_match('/^-\s+\[/', $line) && !preg_match('/^##\s+/', $line)) {
                $summaryLines[] = $line;
            }
        }

        if ($currentMilestone) {
            $currentMilestone['summary'] = trim(implode("\n", $summaryLines));
            $milestones[] = $currentMilestone;
        }

        return ['milestones' => $milestones];
    }

    private function syncMilestones(array $milestones): void
    {
        foreach ($milestones as $m) {
            Milestone::updateOrCreate(
                ['project_id' => $this->project->id, 'milestone_key' => $m['key']],
                [
                    'title' => $m['title'],
                    'summary' => $m['summary'],
                    'position' => $m['position'],
                ]
            );
        }
    }

    private function syncTasks(array $milestones): void
    {
        $memoryStore = app(\App\Projects\Memory\MemoryStore::class);
        $parsedTasks = [];
        foreach ($milestones as $m) {
            $milestone = Milestone::where('project_id', $this->project->id)->where('milestone_key', $m['key'])->first();
            foreach ($m['tasks'] as $t) {
                $parsedTasks[$t['key']] = [
                    'milestone_id' => $milestone?->id,
                    'position' => $t['position'],
                    'title' => $t['title'],
                    'declared_status' => $t['declared_status'],
                ];
            }
        }

        $existingTasks = Task::where('project_id', $this->project->id)->get();

        foreach ($existingTasks as $task) {
            if (isset($parsedTasks[$task->task_key])) {
                $data = $parsedTasks[$task->task_key];
                $oldEffective = self::effectiveStatus($task);

                // Read description from memory
                $descPath = "tasks/{$task->task_key}/task.md";
                $newDescription = $memoryStore->read($this->project, $descPath);

                $task->update([
                    'milestone_id' => $data['milestone_id'],
                    'position' => $data['position'],
                    'title' => $data['title'],
                    'declared_status' => $data['declared_status'],
                    'description' => $newDescription,
                ]);

                $newEffective = self::effectiveStatus($task);
                if ($oldEffective !== $newEffective) {
                    RoadmapEvent::create([
                        'project_id' => $this->project->id,
                        'type' => 'task_status_changed',
                        'subject_key' => $task->task_key,
                        'detail' => "{$oldEffective} → {$newEffective}",
                    ]);
                }
            } else {
                $task->update(['milestone_id' => null]);
                RoadmapEvent::create([
                    'project_id' => $this->project->id,
                    'type' => 'task_removed',
                    'subject_key' => $task->task_key,
                    'detail' => 'removed',
                ]);
            }
        }

        foreach ($parsedTasks as $key => $data) {
            if (!Task::where('project_id', $this->project->id)->where('task_key', $key)->exists()) {
                $descPath = "tasks/{$key}/task.md";
                $newDescription = $memoryStore->read($this->project, $descPath);

                Task::create([
                    'project_id' => $this->project->id,
                    'task_key' => $key,
                    'title' => $data['title'],
                    'milestone_id' => $data['milestone_id'],
                    'position' => $data['position'],
                    'declared_status' => $data['declared_status'],
                    'status' => TaskStatus::Pending,
                    'description' => $newDescription,
                ]);
                RoadmapEvent::create([
                    'project_id' => $this->project->id,
                    'type' => 'task_added',
                    'subject_key' => $key,
                    'detail' => null,
                ]);
            }
        }
    }
}
