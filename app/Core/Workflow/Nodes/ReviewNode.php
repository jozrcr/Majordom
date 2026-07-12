<?php

namespace App\Core\Workflow\Nodes;

use App\Agents\Reviewer\ReviewerService;
use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\ApprovalType;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Projects\Memory\MemoryStore;

/**
 * Frontier review of the Builder's diff (SPEC §3 phase 7). Approved →
 * the human arbitration gate. Changes requested → the comments become the
 * next revision brief and the execution parks (the automated revise loop
 * re-arms with the M4 event bus; for now the owner restarts after reading).
 */
class ReviewNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        $task = $execution->tasks()->first();
        if ($task === null) {
            return NodeResult::failed('Execution has no task.');
        }

        $buildNode = $execution->nodes()
            ->where('type', 'build')
            ->where('status', NodeStatus::Completed)
            ->orderByDesc('id')
            ->first();

        $diff = $buildNode->output['diff'] ?? '';
        if ($diff === '') {
            return NodeResult::failed('No diff to review.');
        }

        $testsPassed = $this->testsPassed($execution);

        $task->update(['status' => TaskStatus::Reviewing]);

        $verdict = app(ReviewerService::class)->review($task, $diff, $testsPassed);

        if (! $verdict->approved) {
            $this->writeRevisionBrief($task, $verdict);
            $revision = $task->fresh()->revision;

            if ($revision > (int) config('majordom.workflow.max_revisions', 3)) {
                return NodeResult::failed(
                    "Reviewer still requesting changes after {$revision} revisions — parked for the owner (task.v{$revision}.md).",
                    ['verdict' => $verdict->toArray()],
                );
            }

            $task->update(['status' => \App\Enums\TaskStatus::Pending]);

            return NodeResult::retry(
                ['build', 'test'],
                "Reviewer requested changes — rebuilding with task.v{$revision}.md.",
                ['verdict' => $verdict->toArray(), 'revision' => $revision],
            );
        }

        $task->update(['status' => TaskStatus::NeedsYou]);

        return NodeResult::waitHuman(
            ApprovalType::Review,
            "Review requested — {$task->task_key}",
            [
                'task_id' => $task->id,
                'diff' => $diff,
                'verdict' => $verdict->toArray(),
                'testsPassed' => $testsPassed,
                'filesChanged' => $buildNode->output['filesChanged'] ?? [],
            ],
            ['verdict' => $verdict->toArray()],
        );
    }

    private function testsPassed(Execution $execution): ?bool
    {
        $testNode = $execution->nodes()
            ->where('type', 'test')
            ->where('status', NodeStatus::Completed)
            ->orderByDesc('id')
            ->first();

        return $testNode->output['testsPassed'] ?? null;
    }

    private function writeRevisionBrief($task, $verdict): void
    {
        $memory = app(MemoryStore::class);
        $key = $task->task_key;

        $base = $memory->read($task->project, "tasks/{$key}/task.md") ?? '';
        $comments = collect($verdict->comments)
            ->map(fn ($c) => '- '.($c['file'] ? "`{$c['file']}`: " : '').$c['comment'])
            ->implode("\n");

        $next = $task->revision + 1;
        $memory->write(
            $task->project,
            "tasks/{$key}/task.v{$next}.md",
            $base."\n\n## Review comments (revision {$next})\n\n"
                .$verdict->summary."\n\n".$comments."\n",
        );

        $task->update(['revision' => $next, 'status' => TaskStatus::Failed]);
    }
}
