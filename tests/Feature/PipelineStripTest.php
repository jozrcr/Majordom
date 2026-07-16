<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Question;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\QuestionStatus;

test('no execution shows idle headline', function () {
    $project = Project::factory()->create();

    livewire(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Idle — no run in progress')
        ->assertDontSee('Working:')
        ->assertDontSee('Waiting for you:');
});

test('running execution shows node chips in order with correct statuses', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create([
        'project_id' => $project->id,
        'status' => ExecutionStatus::Running,
    ]);
    Node::factory()->create(['execution_id' => $exec->id, 'type' => 'decompose', 'status' => NodeStatus::Completed]);
    Node::factory()->create(['execution_id' => $exec->id, 'type' => 'build', 'status' => NodeStatus::Running]);
    Node::factory()->create(['execution_id' => $exec->id, 'type' => 'test', 'status' => NodeStatus::Pending]);

    livewire(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('decompose')
        ->assertSee('build')
        ->assertSee('test')
        ->assertSee('Working: build');
});

test('open question headline wins over working', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create([
        'project_id' => $project->id,
        'status' => ExecutionStatus::Running,
    ]);
    Node::factory()->create(['execution_id' => $exec->id, 'type' => 'build', 'status' => NodeStatus::Running]);
    Question::factory()->create([
        'project_id' => $project->id,
        'status' => QuestionStatus::Open,
    ]);

    livewire(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Waiting for you: answer the Architect\'s question')
        ->assertDontSee('Working:');
});

test('failed node shows failed headline', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create([
        'project_id' => $project->id,
        'status' => ExecutionStatus::Running,
    ]);
    Node::factory()->create(['execution_id' => $exec->id, 'type' => 'build', 'status' => NodeStatus::Failed]);

    livewire(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Failed at build');
});
