<?php

namespace App\Core\Workflow\Nodes;

use App\Agents\Reviewer\ReviewerService;
use App\Core\Events\EventRecorder;
use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\ApprovalType;
use App\Enums\NodeStatus;
use App\Enums\ParkedReason;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Projects\Memory\MemoryStore;
use App\Support\RoleResolver;
use App\Support\Setting;

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

        // Review the task's CUMULATIVE work (base_commit..worktree), not just the
        // last aider run's incremental diff. A Builder that edits minimally on a
        // revision/retry produces a tiny diff; judged alone against the full
        // acceptance criteria it is always (wrongly) rejected, even when the
        // task's earlier revisions already satisfy the criteria. Falls back to
        // the incremental diff when there's no base (greenfield / legacy task).
        $diff = $this->reviewDiff($task, $buildNode->output['diff'] ?? '');
        if ($diff === '') {
            // No-op build: the Builder correctly changed nothing (e.g. a prior
            // task already covers this one). There is literally nothing to
            // review, so accept it and advance rather than parking the run.
            $task->update(['status' => TaskStatus::Approved]);

            app(EventRecorder::class)->record(
                $execution->project,
                'review.noop',
                ['task_key' => $task->task_key],
                $execution,
                'reviewer'
            );

            return NodeResult::done([
                'verdict' => ['approved' => true, 'summary' => 'No changes were needed — nothing to review.', 'comments' => []],
                'autoApproved' => true,
                'noop' => true,
            ]);
        }

        $testsPassed = $this->testsPassed($execution);

        $task->update(['status' => TaskStatus::Reviewing]);

        $roleName = $node->input['role'] ?? 'reviewer';
        $binding = app(RoleResolver::class)->resolve($roleName, $task->project);

        $verdict = app(ReviewerService::class)->review($task, $diff, $testsPassed, $binding);

        // M9 escalation: the failure is the owner's to resolve, not the
        // Builder's — questions instead of another doomed revision.
        if (! $verdict->approved && $verdict->needsClarification()) {
            foreach ($verdict->questions as $q) {
                $execution->questions()->create([
                    'project_id' => $execution->project_id,
                    'text' => $q,
                ]);
            }
            $task->update(['status' => TaskStatus::NeedsYou]);

            return NodeResult::escalated([
                'verdict' => $verdict->toArray(),
                'questions' => $verdict->questions,
            ]);
        }

        if (! $verdict->approved) {
            $this->writeRevisionBrief($task, $verdict);
            $revision = $task->fresh()->revision;

            $budgetBase = (int) ($task->clarified_at_revision ?? 0);
            if ($revision - $budgetBase > (int) Setting::get('workflow.max_revisions', config('majordom.workflow.max_revisions', 3))) {
                $rescueRole = $node->input['config']['rescue_role'] ?? null;
                
                if ($rescueRole) {
                    $buildNode = $execution->nodes()->where('type', 'build')->first();
                    if ($buildNode && ($buildNode->input['config']['rescued'] ?? false)) {
                        return NodeResult::failed(
                            "Reviewer still requesting changes after {$revision} revisions — parked for the owner (task.v{$revision}.md).",
                            ['verdict' => $verdict->toArray()],
                            ParkedReason::ReworkLimit,
                        );
                    }

                    if ($buildNode) {
                        $buildNode->update([
                            'input' => array_merge($buildNode->input ?? [], [
                                'role' => $rescueRole,
                                'config' => array_merge($buildNode->input['config'] ?? [], ['rescued' => true]),
                            ]),
                        ]);
                    }

                    $task->update(['status' => TaskStatus::Pending]);

                    app(EventRecorder::class)->record(
                        $execution->project,
                        'build.rescue',
                        ['rescue_role' => $rescueRole],
                        $execution,
                        'system'
                    );

                    // retryFrom resets this node + earlier build/test rows.
                    return NodeResult::retry(
                        ['build', 'test'],
                        "Budget exhausted — rescuing with role '{$rescueRole}'.",
                        ['verdict' => $verdict->toArray(), 'rescue_role' => $rescueRole],
                    );
                }
                
                return NodeResult::failed(
                    "Reviewer still requesting changes after {$revision} revisions — parked for the owner (task.v{$revision}.md).",
                    ['verdict' => $verdict->toArray()],
                    ParkedReason::ReworkLimit,
                );
            }

            $task->update(['status' => \App\Enums\TaskStatus::Pending]);

            return NodeResult::retry(
                ['build', 'test'],
                "Reviewer requested changes — rebuilding with task.v{$revision}.md.",
                ['verdict' => $verdict->toArray(), 'revision' => $revision],
            );
        }

        // Overnight: the Reviewer's approval stands without arbitration —
        // the diff still ends as a CommitSuggestion the human must apply.
        if ($execution->gateBehavior('review') === 'auto') {
            $task->update(['status' => TaskStatus::Approved]);

            return NodeResult::done([
                'verdict' => $verdict->toArray(),
                'autoApproved' => true,
            ]);
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

    /**
     * The task's cumulative diff since its build baseline (base_commit..worktree),
     * so the Reviewer judges everything the task built across its revisions — not
     * just the latest incremental change. Falls back to the incremental diff when
     * there's no recorded base or the worktree/git call is unavailable.
     */
    private function reviewDiff($task, string $incremental): string
    {
        $worktree = $task->worktree_path;
        $base = $task->base_commit;

        if (! $base || ! $worktree || ! is_dir($worktree)) {
            return $incremental;
        }

        $result = \Illuminate\Support\Facades\Process::path($worktree)->run(['git', 'diff', $base]);
        $cumulative = $result->successful() ? trim($result->output()) : '';

        return $cumulative !== '' ? $cumulative : $incremental;
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
