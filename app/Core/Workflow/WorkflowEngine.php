<?php

namespace App\Core\Workflow;

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

    /** @param string[] $nodeTypes ordered chain, keys of the node map */
    public function start(Execution $execution, array $nodeTypes): void
    {
        foreach ($nodeTypes as $type) {
            $this->assertKnown($type);
            $execution->nodes()->create(['type' => $type]);
        }

        $execution->update(['status' => ExecutionStatus::Running]);
        $execution->project->update(['status' => ProjectStatus::Working, 'last_activity_at' => now()]);

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
