<?php

use App\Enums\ImplementationStrategy;
use App\Livewire\ProjectWorkspace;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Task;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('shows local builder badge for default strategy', function () {
    $project = Project::factory()->create();
    $execution = Execution::create(['project_id' => $project->id, 'status' => 'completed']);
    Task::create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'task_key' => 'T-001',
        'title' => 'Test Task',
        'status' => 'approved',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Local Builder');
});

test('shows frontier downgraded badge when event exists', function () {
    $project = Project::factory()->create();
    $execution = Execution::create(['project_id' => $project->id, 'status' => 'completed']);
    Task::create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'task_key' => 'T-001',
        'title' => 'Test Task',
        'status' => 'approved',
        'implementation_strategy' => ImplementationStrategy::Frontier,
    ]);

    Event::create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'name' => 'build.builder_downgraded',
        'payload' => [],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Frontier → Local');
});
