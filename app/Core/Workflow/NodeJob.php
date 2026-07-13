<?php

namespace App\Core\Workflow;

use App\Core\Events\EventRecorder;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\ProjectStatus;
use App\Models\Execution;
use App\Models\Node;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Base for every workflow node (SPEC §4: each node is a queue Job with typed
 * input/output persisted on its Node row). Subclasses implement run() and
 * return a NodeResult; this class owns the lifecycle bookkeeping so a node
 * can never leave the execution in an ambiguous state.
 */
abstract class NodeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $nodeId) {}

    abstract protected function run(Node $node, Execution $execution): NodeResult;

    public function handle(): void
    {
        $node = Node::findOrFail($this->nodeId);
        $execution = $node->execution;

        // A parked/completed execution never runs queued leftovers.
        if ($execution->status !== ExecutionStatus::Running) {
            return;
        }

        // Frontier-spend cap (SPEC §8): exceeding it parks like any gate.
        if ($execution->spend_cap_usd !== null) {
            $spent = (float) \App\Models\UsageRecord::where('execution_id', $execution->id)->sum('cost_usd');
            if ($spent > (float) $execution->spend_cap_usd) {
                $node->fail(['reason' => 'spend cap']);
                $execution->park(sprintf('Frontier spend cap exceeded ($%.4f of $%.4f) — parked for the owner.', $spent, $execution->spend_cap_usd));
                $execution->project->update(['status' => \App\Enums\ProjectStatus::NeedsYou, 'last_activity_at' => now()]);

                return;
            }
        }

        $node->start();
        $execution->update(['current_node' => $node->type]);

        app(EventRecorder::class)->record(
            $execution->project,
            "{$node->type}.started",
            [],
            $execution,
            $this->actorFor($node)
        );

        try {
            $result = $this->run($node, $execution);
        } catch (\Throwable $e) {
            $this->parkOn($node, $execution, $e->getMessage(), ['exception' => $e::class]);

            throw $e;
        }

        match ($result->status) {
            'done' => $this->completeAndAdvance($node, $execution, $result),
            'waiting' => $this->waitForHuman($node, $execution, $result),
            'failed' => $this->parkOn($node, $execution, $result->failureReason, $result->output),
            'retry' => $this->retryFrom($node, $execution, $result),
            'escalated' => $this->waitForAnswers($node, $execution, $result),
        };
    }

    /**
     * M9 escalation: questions for the owner exist; no Approval row — the
     * chain resumes via WorkflowEngine::resumeAfterClarification once the
     * last one is answered.
     */
    private function waitForAnswers(Node $node, Execution $execution, NodeResult $result): void
    {
        $node->update(['status' => NodeStatus::WaitingHuman, 'output' => $result->output]);

        $execution->update(['status' => ExecutionStatus::NeedsYou]);
        $execution->project->update(['status' => ProjectStatus::NeedsYou, 'last_activity_at' => now()]);

        app(EventRecorder::class)->record(
            $execution->project,
            "{$node->type}.clarification",
            ['questions' => count($result->output['questions'] ?? [])],
            $execution,
            $this->actorFor($node)
        );
    }

    /**
     * The bounded revise loop: this node and the named earlier types go back
     * to pending; advance() re-runs them in chain order with the revision
     * brief in play.
     */
    private function retryFrom(Node $node, Execution $execution, NodeResult $result): void
    {
        $node->update(['status' => NodeStatus::Pending, 'output' => $result->output, 'finished_at' => null]);

        $execution->nodes()
            ->whereIn('type', $result->retryResets)
            ->where('id', '<', $node->id)
            ->update(['status' => NodeStatus::Pending, 'finished_at' => null]);

        app(EventRecorder::class)->record(
            $execution->project,
            "{$node->type}.retry",
            ['reason' => $result->failureReason],
            $execution,
            $this->actorFor($node)
        );

        app(WorkflowEngine::class)->advance($execution->fresh());
    }

    public function failed(?\Throwable $e): void
    {
        // Death before/outside handle()'s own catch (stale worker, timeout
        // kill): make the failure visible instead of a silent stall.
        $node = Node::find($this->nodeId);
        if ($node && $node->status === NodeStatus::Running) {
            $execution = $node->execution;
            $this->parkOn($node, $execution, $e?->getMessage() ?? 'node died unexpectedly', []);
        }
    }

    private function completeAndAdvance(Node $node, Execution $execution, NodeResult $result): void
    {
        $node->finish($result->output);
        
        $payload = [];
        if (isset($result->output['summary'])) $payload['summary'] = $result->output['summary'];
        if (isset($result->output['filesChanged'])) $payload['filesChanged'] = $result->output['filesChanged'];

        app(EventRecorder::class)->record(
            $execution->project,
            "{$node->type}.completed",
            $payload,
            $execution,
            $this->actorFor($node)
        );

        app(WorkflowEngine::class)->advance($execution->fresh());
    }

    private function waitForHuman(Node $node, Execution $execution, NodeResult $result): void
    {
        $node->update(['status' => NodeStatus::WaitingHuman, 'output' => $result->output]);

        $execution->approvals()->create([
            'project_id' => $execution->project_id,
            'type' => $result->approvalType,
            'title' => $result->approvalTitle,
            'payload' => $result->approvalPayload + ['node_id' => $node->id],
        ]);

        app(EventRecorder::class)->record(
            $execution->project,
            "{$node->type}.waiting_human",
            ['title' => $result->approvalTitle],
            $execution,
            $this->actorFor($node)
        );

        $execution->update(['status' => ExecutionStatus::NeedsYou]);
        $execution->project->update(['status' => ProjectStatus::NeedsYou, 'last_activity_at' => now()]);
    }

    private function parkOn(Node $node, Execution $execution, string $reason, array $output): void
    {
        $node->fail($output + ['reason' => $reason]);
        $execution->park($reason);
        $execution->project->update(['status' => ProjectStatus::Parked, 'last_activity_at' => now()]);

        app(EventRecorder::class)->record(
            $execution->project,
            "{$node->type}.failed",
            ['reason' => $reason],
            $execution,
            $this->actorFor($node)
        );
    }

    private function actorFor(Node $node): string
    {
        if (str_contains($node->type, 'build')) return 'builder';
        if (str_contains($node->type, 'review')) return 'reviewer';
        return 'system';
    }
}
