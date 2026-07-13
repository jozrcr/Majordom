<?php

namespace App\Core\Workflow;

use App\Core\Events\EventRecorder;
use App\Enums\ApprovalStatus;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\ProjectStatus;
use App\Models\Approval;
use App\Models\Execution;
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

            return;
        }

        $job = $this->nodeMap[$next->type] ?? null;
        if ($job === null) {
            $execution->park("No job registered for node type '{$next->type}'.");

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
