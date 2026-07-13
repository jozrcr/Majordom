<?php

namespace App\Core\Workflow\Nodes;

use App\Agents\Reviewer\ReviewerService;
use App\Core\Events\EventRecorder;
use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\ApprovalType;
use App\Enums\NodeStatus;
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

        $diff = $buildNode->output['diff'] ?? '';
        if ($diff === '') {
            return NodeResult::failed('No diff to review.');
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
                    if ($buildNode && ($buildNode->input['rescued'] ?? false)) {
                        return NodeResult::failed(
                            "Reviewer still requesting changes after {$revision} revisions — parked for the owner (task.v{$revision}.md).",
                            ['verdict' => $verdict->toArray()],
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
                    
                    $execution->nodes()
                        ->whereIn('type', ['build', 'test', 'review'])
                        ->whereIn('status', [NodeStatus::Completed, NodeStatus::WaitingHuman, NodeStatus::Failed])
                        ->update(['status' => NodeStatus::Pending, 'finished_at' => null]);
                        
                    $task->update(['status' => TaskStatus::Pending]);
                    
                    app(EventRecorder::class)->record(
                        $execution->project,
                        'build.rescue',
                        ['rescue_role' => $rescueRole],
                        $execution,
                        'system'
                    );
                    
                    return NodeResult::retry(
                        ['build', 'test'],
                        "Budget exhausted — rescuing with role '{$rescueRole}'.",
                        ['verdict' => $verdict->toArray(), 'rescue_role' => $rescueRole],
                    );
                }
                
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
