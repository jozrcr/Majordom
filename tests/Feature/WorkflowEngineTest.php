<?php

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Core\Workflow\WorkflowEngine;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\ProjectStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;

class ScriptDoneNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        return NodeResult::done(['ran' => $node->type]);
    }
}

class ScriptGateNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        return NodeResult::waitHuman(ApprovalType::Review, 'Review requested', ['diff' => '+x']);
    }
}

class ScriptFailNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        return NodeResult::failed('scripted failure');
    }
}

class ScriptThrowNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        throw new RuntimeException('boom');
    }
}

function engine(): WorkflowEngine
{
    $engine = new WorkflowEngine([
        'a' => ScriptDoneNode::class,
        'b' => ScriptDoneNode::class,
        'gate' => ScriptGateNode::class,
        'fail' => ScriptFailNode::class,
        'throw' => ScriptThrowNode::class,
    ]);
    app()->instance(WorkflowEngine::class, $engine);

    return $engine;
}

beforeEach(function () {
    // Chain runs synchronously through the real job classes.
    config(['queue.connections.harness.driver' => 'sync']);
    $this->project = Project::factory()->create();
    $this->execution = Execution::factory()->create(['project_id' => $this->project->id]);
});

it('runs a chain of nodes to completion', function () {
    engine()->start($this->execution, ['a', 'b']);

    $this->execution->refresh();
    expect($this->execution->status)->toBe(ExecutionStatus::Completed)
        ->and($this->execution->nodes()->where('status', NodeStatus::Completed)->count())->toBe(2)
        ->and($this->execution->nodes()->first()->output)->toBe(['ran' => 'a'])
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
});

it('parks the chain behind a human gate and resumes on grant', function () {
    engine()->start($this->execution, ['a', 'gate', 'b']);

    $this->execution->refresh();
    expect($this->execution->status)->toBe(ExecutionStatus::NeedsYou)
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::NeedsYou);

    $approval = $this->execution->approvals()->first();
    expect($approval->type)->toBe(ApprovalType::Review)
        ->and($approval->payload['diff'])->toBe('+x');

    engine()->resolveApproval($approval, granted: true, comment: 'lgtm');

    $this->execution->refresh();
    expect($this->execution->status)->toBe(ExecutionStatus::Completed)
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Granted);

    $gateNode = $this->execution->nodes()->where('type', 'gate')->first();
    expect($gateNode->status)->toBe(NodeStatus::Completed)
        ->and($gateNode->output['decision'])->toBe('granted')
        ->and($gateNode->output['comment'])->toBe('lgtm');
});

it('parks the execution on rejection', function () {
    engine()->start($this->execution, ['gate', 'b']);
    $approval = $this->execution->approvals()->first();

    engine()->resolveApproval($approval, granted: false, comment: 'wrong direction');

    $this->execution->refresh();
    expect($this->execution->status)->toBe(ExecutionStatus::Parked)
        ->and($this->execution->meta['parked_reason'])->toContain('wrong direction')
        ->and($this->execution->nodes()->where('type', 'b')->first()->status)->toBe(NodeStatus::Pending)
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Parked);
});

it('parks on a failed node and never runs the rest', function () {
    engine()->start($this->execution, ['fail', 'b']);

    $this->execution->refresh();
    expect($this->execution->status)->toBe(ExecutionStatus::Parked)
        ->and($this->execution->meta['parked_reason'])->toBe('scripted failure')
        ->and($this->execution->nodes()->where('type', 'b')->first()->status)->toBe(NodeStatus::Pending);
});

it('parks on a throwing node with the exception recorded', function () {
    try {
        engine()->start($this->execution, ['throw']);
    } catch (RuntimeException) {
        // sync driver rethrows; the engine must have parked first
    }

    $this->execution->refresh();
    expect($this->execution->status)->toBe(ExecutionStatus::Parked)
        ->and($this->execution->meta['parked_reason'])->toBe('boom')
        ->and($this->execution->nodes()->first()->output['exception'])->toBe(RuntimeException::class);
});

it('refuses to start with an unknown node type', function () {
    expect(fn () => engine()->start($this->execution, ['nope']))
        ->toThrow(InvalidArgumentException::class);
});

it('ignores stale resolutions and non-running executions', function () {
    engine()->start($this->execution, ['gate']);
    $approval = $this->execution->approvals()->first();

    engine()->resolveApproval($approval, true);
    $completedAt = $this->execution->fresh()->status;

    // Second resolution is a no-op.
    engine()->resolveApproval($approval->fresh(), false);
    expect($this->execution->fresh()->status)->toBe($completedAt);
});
