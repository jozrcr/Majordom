<?php

use App\Core\Workflow\Nodes\ReviewNode;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('review auto-approves a no-op build (empty diff) instead of parking', function () {
    Queue::fake();
    setupMemoryRoot();

    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running, 'profile' => 'attended']);
    $task = Task::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'task_key' => 'T-001', 'status' => TaskStatus::Testing]);

    // A completed build that produced NO diff (the Builder correctly changed nothing).
    Node::factory()->create(['execution_id' => $execution->id, 'type' => 'build', 'status' => NodeStatus::Completed,
        'output' => ['diff' => '', 'filesChanged' => [], 'summary' => 'No changes were needed for this task.']]);
    Node::factory()->create(['execution_id' => $execution->id, 'type' => 'test', 'status' => NodeStatus::Completed, 'output' => ['testsPassed' => null]]);
    $review = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::Pending]);

    (new ReviewNode($review->id))->handle();

    // Approved without an LLM call, without a human gate, without parking.
    expect($review->fresh()->status)->toBe(NodeStatus::Completed);
    expect($task->fresh()->status)->toBe(TaskStatus::Approved);
    expect($execution->fresh()->status)->not->toBe(ExecutionStatus::Parked);
    expect(Event::where('name', 'review.noop')->where('project_id', $project->id)->exists())->toBeTrue();
    // no review gate approval was raised
    expect($project->approvals()->where('type', \App\Enums\ApprovalType::Review)->exists())->toBeFalse();
});
