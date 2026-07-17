<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Models\Execution;
use App\Models\Task;
use App\Models\Milestone;
use App\Models\Event;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('activity panel shows milestone task label when linked', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2']);
    $task = Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id, 'task_key' => 'T-014']);
    $execution = Execution::factory()->create(['project_id' => $project->id, 'task_id' => $task->id]);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'name' => 'node.started']);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('M2 · T-014');
});

test('activity panel falls back to execution id when no task linked', function () {
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'task_id' => null]);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'name' => 'node.started']);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee("execution #{$execution->id}");
});

test('past execution sections are collapsed and current is open', function () {
    $project = Project::factory()->create();
    $pastExec = Execution::factory()->create(['project_id' => $project->id]);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $pastExec->id, 'name' => 'node.completed']);
    
    $currentExec = Execution::factory()->create(['project_id' => $project->id]);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $currentExec->id, 'name' => 'node.running']);

    $component = Livewire::test(ProjectWorkspace::class, ['project' => $project]);
    
    // Past section should have x-data with open: false
    $component->assertSee("x-data=\"{ open: false }\"");
    // Current section should have x-data with open: true
    $component->assertSee("x-data=\"{ open: true }\"");
});

test('answered questions are grouped into a single row', function () {
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id]);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'name' => 'question.answered']);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'name' => 'question.discarded']);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'name' => 'node.started']);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('2 answered');
});

test('wire poll attribute is present', function () {
    $project = Project::factory()->create();
    
    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('wire:poll');
});
