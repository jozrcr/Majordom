<?php

namespace App\Core\Workflow\Nodes;

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;

class DelegateNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        /** @var Task|null $task */
        $task = $execution->tasks()->first();
        if (!$task) {
            return NodeResult::failed('Execution has no task.');
        }

        $memory = app(MemoryStore::class);
        $project = $task->project;
        $taskKey = $task->task_key;
        $rolePath = "tasks/{$taskKey}/role.md";
        $taskPath = "tasks/{$taskKey}/task.md";

        if (!$memory->exists($project, $rolePath)) {
            $memory->write($project, $rolePath, <<<MD
You are the Builder: a careful software engineer implementing exactly one scoped task.
Rules: make the smallest change that satisfies the acceptance criteria; follow the
existing code style; do not refactor unrelated code; do not touch files outside the
task's scope; never push. If the task is impossible as specified, say so plainly.
MD
            );
        }

        if (trim((string) $memory->read($project, $taskPath)) === '') {
            return NodeResult::failed("Task brief at tasks/{$taskKey}/task.md is missing or empty — re-run planning (approve the plan again in the chat).");
        }

        try {
            // M12: tasks in a milestone share ONE worktree on majordom/<key>,
            // building on each other; each task's aider commits land there and
            // the whole milestone is merged to main as the gated promotion.
            // Legacy tasks (no milestone) keep a per-task worktree.
            $wtm = app(WorktreeManager::class);
            if ($task->milestone) {
                $path = $wtm->ensureMilestoneWorktree($project, $task->milestone);
                $task->worktree_path = $path;
                $task->branch = $wtm->branchForMilestone($task->milestone);
                $task->save();
            } else {
                $path = $wtm->create($task);
            }
        } catch (\Throwable $e) {
            return NodeResult::failed('Failed to create worktree: '.$e->getMessage());
        }

        // Record the pre-work commit ONCE, on the task's first build, so the
        // Reviewer can later judge the task's cumulative work (base_commit..HEAD)
        // rather than the last aider run's incremental diff. Guarded to the first
        // build (revision <= 1) so retries of an already-built task don't capture
        // a base that already contains the work.
        if ($task->base_commit === null && (int) ($task->revision ?? 1) <= 1 && is_dir($path)) {
            $head = Process::path($path)->run(['git', 'rev-parse', 'HEAD']);
            if ($head->successful()) {
                $task->base_commit = trim($head->output());
            }
        }

        $task->status = TaskStatus::Building;
        $task->save();

        return NodeResult::done([
            'task_id' => $task->id,
            'worktree' => $path,
            'branch' => $task->branch,
        ]);
    }
}
