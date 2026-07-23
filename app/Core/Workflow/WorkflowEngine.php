<?php

namespace App\Core\Workflow;

use App\Core\Events\EventRecorder;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\ParkedReason;
use App\Enums\ProjectStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Milestone;
use App\Models\Node;

/**
 * The sequential v1 engine (SPEC §3/§4): an Execution owns an ordered chain
 * of Node rows; each node runs as a queued job; human gates park the chain
 * behind an Approval and resolveApproval() resumes it. The node-type → job
 * map is injected so tests drive the engine with scripted nodes; the real
 * map ships with the *Implement Feature* workflow (M3 nodes register here).
 */
class WorkflowEngine
{
    /** @param array<string, class-string<NodeJob>> $nodeMap */
    public function __construct(private array $nodeMap = []) {}

    public function knownTypes(): array
    {
        return array_keys($this->nodeMap);
    }

    /** @param string[] $nodeTypes ordered chain, keys of the node map */
    public function start(Execution $execution, array $nodeTypes): void
    {
        $steps = ChainStep::normalize($nodeTypes);
        foreach ($steps as $step) {
            $this->assertKnown($step->type);
            $execution->nodes()->create([
                'type' => $step->type,
                'input' => ['role' => $step->role, 'config' => $step->config],
            ]);
        }

        $execution->update(['status' => ExecutionStatus::Running]);
        $execution->project->update(['status' => ProjectStatus::Working, 'last_activity_at' => now()]);

        app(EventRecorder::class)->record(
            $execution->project,
            'workflow.started',
            ['nodeTypes' => $nodeTypes],
            $execution,
            'system'
        );

        $this->advance($execution);
    }

    /** Dispatch the next pending node, or complete the execution. */
    public function advance(Execution $execution): void
    {
        if ($execution->status !== ExecutionStatus::Running) {
            return;
        }

        $next = $execution->nodes()
            ->where('status', NodeStatus::Pending)
            ->orderBy('id')
            ->first();

        if ($next === null) {
            $execution->update(['status' => ExecutionStatus::Completed, 'current_node' => null]);
            $execution->project->update(['status' => ProjectStatus::Idle, 'last_activity_at' => now()]);

            app(EventRecorder::class)->record(
                $execution->project,
                'workflow.completed',
                [],
                $execution,
                'system'
            );

            // M12 autonomy loop: in the auto-commit flow (a `finalize` node, no
            // per-task commit checkpoint), advance to the next task in the
            // milestone now. When the chain has a `commit_suggestion` gate
            // (confirm_commits), the advance waits for the human's approval
            // (CommitService::apply) instead. Fire-and-forget — never let a
            // chain hiccup break execution completion.
            $hasCheckpoint = $execution->nodes()->where('type', 'commit_suggestion')->exists();
            if (! $hasCheckpoint) {
                $task = $execution->tasks()->first();
                if ($task) {
                    try {
                        app(\App\Core\Workflow\TaskChain::class)->advance($task->fresh());
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            return;
        }

        $job = $this->nodeMap[$next->type] ?? null;
        if ($job === null) {
            $reason = "No job registered for node type '{$next->type}'.";
            $execution->park($reason, ParkedReason::HarnessFailure);
            app(EscalationRouter::class)->route($execution, ParkedReason::HarnessFailure, $reason, $next->type);

            return;
        }

        $job::dispatch($next->id)->onConnection('harness')->onQueue('harness');
    }

    /**
     * A human resolved a gate. Grant → the waiting node finishes (decision
     * merged into its output) and the chain advances. Reject → the node
     * records it and the execution parks; node-specific revise loops (M3
     * review round-trips) are the gate-creating node's job to re-arm.
     */
    public function resolveApproval(Approval $approval, bool $granted, ?string $comment = null): void
    {
        if ($approval->status !== ApprovalStatus::Open) {
            return;
        }

        // The milestone gate (M12/M16-A) is not tied to a workflow node and has
        // its own three-way resolution: grant → merge; a bare decline → DEFER
        // (kept ready, never a dead end); a decline WITH a reason is routed via
        // requestMilestoneGateChanges (the modal calls that directly).
        if ($approval->type === ApprovalType::MilestoneMerge) {
            if ($granted) {
                $approval->grant();
                $this->mergeGrantedMilestone($approval, $comment);
            } else {
                $this->deferMilestoneGate($approval);
            }

            return;
        }

        $granted ? $approval->grant() : $approval->reject();

        $execution = $approval->execution;
        $node = Node::find($approval->payload['node_id'] ?? 0);

        if ($execution === null || $node === null || $node->status !== NodeStatus::WaitingHuman) {
            return;
        }

        $decision = [
            'decision' => $granted ? 'granted' : 'rejected',
            'comment' => $comment,
        ];

        app(EventRecorder::class)->record(
            $execution->project,
            $granted ? 'approval.granted' : 'approval.rejected',
            ['title' => $approval->title, 'comment' => $comment],
            $execution,
            'you'
        );

        if ($granted) {
            $node->finish(($node->output ?? []) + $decision);
            $execution->update(['status' => ExecutionStatus::Running]);
            $execution->project->update(['status' => ProjectStatus::Working, 'last_activity_at' => now()]);
            $this->advance($execution->fresh());

            return;
        }

        $node->update(['output' => ($node->output ?? []) + $decision, 'finished_at' => now()]);
        $execution->park('Rejected by the owner'.($comment ? ": {$comment}" : '.'));
        $execution->project->update(['status' => ProjectStatus::Parked, 'last_activity_at' => now()]);
    }

    /**
     * Grant path: promote the milestone branch into main and roll into the next
     * milestone's first task. Shared by the open-gate grant and the "merge a
     * deferred gate later" action.
     */
    private function mergeGrantedMilestone(Approval $approval, ?string $comment): void
    {
        $project = $approval->project;

        app(EventRecorder::class)->record(
            $project,
            'approval.granted',
            ['title' => $approval->title, 'comment' => $comment],
            $approval->execution,
            'you'
        );

        $milestone = Milestone::find($approval->payload['milestone_id'] ?? 0);
        if ($milestone === null) {
            return;
        }
        $profile = $approval->payload['profile'] ?? 'attended';

        app(\App\Projects\Repositories\CommitService::class)->mergeMilestone($milestone);
        app(\App\Core\Workflow\TaskChain::class)->startNextMilestone($project, $milestone, $profile);
    }

    /**
     * Decline WITHOUT a reason (M16-A): set the merge aside, kept ready. The
     * branch and worktree stay intact and the gate becomes re-openable — never
     * the silent idle dead-end the owner hit before. Not the reviewer's call;
     * only the owner defers.
     */
    public function deferMilestoneGate(Approval $approval): void
    {
        if ($approval->status !== ApprovalStatus::Open || $approval->type !== ApprovalType::MilestoneMerge) {
            return;
        }

        $approval->defer();
        $project = $approval->project;

        app(EventRecorder::class)->record(
            $project,
            'milestone.merge_deferred',
            ['title' => $approval->title, 'milestone_id' => $approval->payload['milestone_id'] ?? null],
            $approval->execution,
            'you'
        );

        $project->update(['status' => ProjectStatus::Idle, 'last_activity_at' => now()]);
    }

    /**
     * Decline WITH a reason (M16-A, kills finding #5): the owner's feedback is
     * not dropped — it routes to the Architect as ONE keyed fix-task that
     * rebuilds and re-reviews, raising a fresh gate when it lands.
     */
    public function requestMilestoneGateChanges(Approval $approval, string $comment): void
    {
        $comment = trim($comment);
        if ($approval->status !== ApprovalStatus::Open || $approval->type !== ApprovalType::MilestoneMerge || $comment === '') {
            return;
        }

        $approval->reject();
        $project = $approval->project;

        app(EventRecorder::class)->record(
            $project,
            'approval.rejected',
            ['title' => $approval->title, 'comment' => $comment],
            $approval->execution,
            'you'
        );

        $milestone = Milestone::find($approval->payload['milestone_id'] ?? 0);
        if ($milestone === null) {
            return;
        }
        $profile = $approval->payload['profile'] ?? 'attended';

        app(\App\Core\Workflow\TaskChain::class)->requestChangesFromOwner($project, $milestone, $comment, $profile);
    }

    /** Owner merges a previously deferred gate. Same promotion as an open grant. */
    public function mergeDeferredMilestoneGate(Approval $approval): void
    {
        if ($approval->status !== ApprovalStatus::Deferred || $approval->type !== ApprovalType::MilestoneMerge) {
            return;
        }

        $approval->grant();
        $this->mergeGrantedMilestone($approval, null);
    }

    /**
     * M9: the owner answered the last escalated question. Their answers
     * become the next revision brief, the budget resets (human input is new
     * information), the loop re-arms from build.
     */
    public function resumeAfterClarification(Execution $execution): void
    {
        if ($execution->status !== ExecutionStatus::NeedsYou) {
            return;
        }

        $task = $execution->tasks()->first();
        if ($task === null) {
            return;
        }

        $answered = $execution->questions()
            ->where('status', \App\Enums\QuestionStatus::Answered)
            ->orderBy('id')->get();

        $memory = app(\App\Projects\Memory\MemoryStore::class);
        $base = $memory->read($execution->project, "tasks/{$task->task_key}/task.md") ?? '';
        $next = $task->revision + 1;

        $qa = $answered->map(fn ($q) => "**Q:** {$q->text}\n**A:** {$q->answer}")->implode("\n\n");
        $memory->write(
            $execution->project,
            "tasks/{$task->task_key}/task.v{$next}.md",
            $base."\n\n## Owner clarifications (revision {$next})\n\n".$qa."\n",
        );

        $task->update([
            'revision' => $next,
            'clarified_at_revision' => $next, // budget resets from here
            'status' => \App\Enums\TaskStatus::Pending,
        ]);

        // Re-arm the loop: build/test and the waiting review go back to pending.
        $execution->nodes()
            ->whereIn('type', ['build', 'test', 'review'])
            ->whereIn('status', [NodeStatus::Completed, NodeStatus::WaitingHuman, NodeStatus::Failed])
            ->update(['status' => NodeStatus::Pending, 'finished_at' => null]);

        $execution->update(['status' => ExecutionStatus::Running]);
        $execution->project->update(['status' => \App\Enums\ProjectStatus::Working, 'last_activity_at' => now()]);

        app(\App\Core\Events\EventRecorder::class)->record(
            $execution->project,
            'clarification.resolved',
            ['answers' => $answered->count(), 'revision' => $next],
            $execution,
            'you'
        );

        $this->advance($execution->fresh());
    }

    /**
     * Owner retries a parked execution: failed nodes go back to pending
     * and the chain re-runs from there.
     */
    public function resumeParked(Execution $execution): void
    {
        if ($execution->status !== ExecutionStatus::Parked) {
            return;
        }

        $execution->nodes()
            ->where('status', NodeStatus::Failed)
            ->update(['status' => NodeStatus::Pending, 'finished_at' => null]);

        $meta = $execution->meta ?? [];
        unset($meta['parked_reason']);
        unset($meta['parked_reason_class']);
        $execution->update(['status' => ExecutionStatus::Running, 'meta' => $meta]);
        $execution->project->update(['status' => \App\Enums\ProjectStatus::Working, 'last_activity_at' => now()]);

        app(EventRecorder::class)->record(
            $execution->project,
            'workflow.resumed',
            [],
            $execution,
            'you'
        );

        $this->advance($execution->fresh());
    }

    /**
     * Owner gives up on a parked execution: remaining nodes are marked
     * failed and the run closes out so it stops surfacing as actionable.
     */
    public function abandonParked(Execution $execution): void
    {
        if ($execution->status !== ExecutionStatus::Parked) {
            return;
        }

        $execution->nodes()
            ->whereIn('status', [NodeStatus::Pending, NodeStatus::WaitingHuman, NodeStatus::Running])
            ->update(['status' => NodeStatus::Failed, 'finished_at' => now()]);

        $execution->update([
            'status' => ExecutionStatus::Completed,
            'current_node' => null,
            'meta' => array_merge($execution->meta ?? [], ['abandoned' => true]),
        ]);
        $execution->project->update(['status' => \App\Enums\ProjectStatus::Idle, 'last_activity_at' => now()]);

        app(EventRecorder::class)->record(
            $execution->project,
            'workflow.abandoned',
            [],
            $execution,
            'you'
        );
    }

    /**
     * Reset the execution loop after a milestone/spec redefine (M14a/T-62).
     * A revised roadmap must not resume the old, possibly-poisoned cycle: close
     * any non-terminal execution (unfinished nodes fail) and re-arm every
     * mid-flight task to Pending so "Start build" rebuilds from the new briefs.
     * Done/Approved tasks (the immutable past) are left untouched.
     *
     * Returns the revised roadmap's first PENDING task key (by milestone then
     * task position) — the point the loop should restart from — or null if the
     * roadmap has nothing left to build. The caller re-arms the "Start build"
     * trigger and regenerates that task's brief from the new roadmap.
     */
    public function resetForRedefine(\App\Models\Project $project): ?string
    {
        $exec = $project->executions()->latest('id')->first();

        if ($exec && in_array($exec->status, [ExecutionStatus::Running, ExecutionStatus::NeedsYou, ExecutionStatus::Parked], true)) {
            $exec->nodes()
                ->whereIn('status', [NodeStatus::Pending, NodeStatus::WaitingHuman, NodeStatus::Running])
                ->update(['status' => NodeStatus::Failed, 'finished_at' => now()]);

            $meta = $exec->meta ?? [];
            unset($meta['parked_reason'], $meta['parked_reason_class']);
            $exec->update([
                'status' => ExecutionStatus::Completed,
                'current_node' => null,
                'meta' => array_merge($meta, ['superseded_by_redefine' => true]),
            ]);
        }

        $project->tasks()
            ->whereIn('status', [
                \App\Enums\TaskStatus::Building,
                \App\Enums\TaskStatus::Testing,
                \App\Enums\TaskStatus::Reviewing,
                \App\Enums\TaskStatus::NeedsYou,
                \App\Enums\TaskStatus::Failed,
            ])
            ->update(['status' => \App\Enums\TaskStatus::Pending]);

        $project->update(['status' => ProjectStatus::Idle, 'last_activity_at' => now()]);

        // The revised loop restarts at the first still-pending task, in roadmap
        // order (milestone position, then task position). Tasks without a
        // milestone (legacy/single-task) fall back to project task position.
        $firstPending = \App\Models\Task::query()
            ->where('tasks.project_id', $project->id)
            ->where('tasks.status', \App\Enums\TaskStatus::Pending)
            ->leftJoin('milestones', 'tasks.milestone_id', '=', 'milestones.id')
            ->orderByRaw('COALESCE(milestones.position, 0)')
            ->orderBy('tasks.position')
            ->select('tasks.*')
            ->first();

        app(EventRecorder::class)->record(
            $project,
            'plan.redefine_reset',
            ['execution_id' => $exec?->id, 'first_task_key' => $firstPending?->task_key],
            $exec,
            'system'
        );

        return $firstPending?->task_key;
    }

    public function knows(string $type): bool
    {
        return isset($this->nodeMap[$type]);
    }

    private function assertKnown(string $type): void
    {
        if (! $this->knows($type)) {
            throw new \InvalidArgumentException("Unknown node type '{$type}'.");
        }
    }
}
