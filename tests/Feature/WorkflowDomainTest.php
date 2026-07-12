<?php

use App\Enums\ApprovalStatus;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('execution factory round-trips status enum and park sets status + reason in meta', function () {
    $execution = Execution::factory()->create(['status' => ExecutionStatus::Running]);
    expect($execution->status)->toBe(ExecutionStatus::Running);

    $execution->park('Waiting for CI');
    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Parked)
        ->and($execution->meta)->toHaveKey('parked_reason', 'Waiting for CI');
});

test('node start/finish/fail helpers set statuses, timestamps, output', function () {
    $node = Node::factory()->create();
    expect($node->status)->toBe(NodeStatus::Pending)
        ->and($node->started_at)->toBeNull()
        ->and($node->finished_at)->toBeNull();

    $node->start();
    $node->refresh();
    expect($node->status)->toBe(NodeStatus::Running)
        ->and($node->started_at)->not()->toBeNull();

    $node->finish(['result' => 'success']);
    $node->refresh();
    expect($node->status)->toBe(NodeStatus::Completed)
        ->and($node->output)->toBe(['result' => 'success'])
        ->and($node->finished_at)->not()->toBeNull();

    $node2 = Node::factory()->create();
    $node2->fail(['error' => 'timeout']);
    $node2->refresh();
    expect($node2->status)->toBe(NodeStatus::Failed)
        ->and($node2->output)->toBe(['error' => 'timeout'])
        ->and($node2->finished_at)->not()->toBeNull();
});

test('approval grant/reject set status + resolved_at and scopeOpen filters', function () {
    $openApproval = Approval::factory()->create(['status' => ApprovalStatus::Open]);
    $grantedApproval = Approval::factory()->create(['status' => ApprovalStatus::Granted]);

    expect(Approval::open()->count())->toBe(1);

    $openApproval->grant();
    $openApproval->refresh();
    expect($openApproval->status)->toBe(ApprovalStatus::Granted)
        ->and($openApproval->resolved_at)->not()->toBeNull();

    $rejectApproval = Approval::factory()->create(['status' => ApprovalStatus::Open]);
    $rejectApproval->reject();
    $rejectApproval->refresh();
    expect($rejectApproval->status)->toBe(ApprovalStatus::Rejected)
        ->and($rejectApproval->resolved_at)->not()->toBeNull();

    expect(Approval::open()->count())->toBe(0);
});

test('task belongs to execution and project and status enum round-trips', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Pending]);
    expect($task->project)->toBeInstanceOf(Project::class)
        ->and($task->execution)->toBeInstanceOf(Execution::class)
        ->and($task->status)->toBe(TaskStatus::Pending);
});

test('deleting a project cascades executions, tasks, nodes, approvals', function () {
    $project = Project::factory()->create();
    $execution = Execution::factory()->for($project)->create();
    Node::factory()->count(2)->for($execution)->create();
    Task::factory()->count(2)->for($execution)->for($project)->create();
    Approval::factory()->count(2)->for($execution)->for($project)->create();

    $project->delete();

    expect(Execution::count())->toBe(0)
        ->and(Task::count())->toBe(0)
        ->and(Node::count())->toBe(0)
        ->and(Approval::count())->toBe(0);
});

test('Project::openApprovals returns only open ones', function () {
    $project = Project::factory()->create();
    Approval::factory()->count(3)->for($project)->create(['status' => ApprovalStatus::Open]);
    Approval::factory()->count(2)->for($project)->create(['status' => ApprovalStatus::Granted]);

    expect($project->openApprovals()->count())->toBe(3)
        ->and($project->approvals()->count())->toBe(5);
});
