<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('selectEvent toggles selectedEventId', function () {
    $project = Project::factory()->create();
    $event = Event::factory()->create(['project_id' => $project->id]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('selectEvent', $event->id)
        ->assertSet('selectedEventId', $event->id)
        ->call('selectEvent', $event->id)
        ->assertSet('selectedEventId', null);
});

test('detail shows payload JSON for a plain event', function () {
    $project = Project::factory()->create();
    $event = Event::factory()->create([
        'project_id' => $project->id,
        'payload' => ['reason' => 'abc']
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('selectEvent', $event->id)
        ->assertSee('reason')
        ->assertSee('abc');
});

test('node-linked event shows node details', function () {
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id]);
    
    $node = Node::factory()->create([
        'execution_id' => $execution->id,
        'type' => 'build',
        'status' => \App\Enums\NodeStatus::Completed,
        'started_at' => now()->subHour(),
        'finished_at' => now(),
        'output' => [
            'rawLog' => 'build log line',
            'diff' => '+ added line',
            'summary' => 'build succeeded'
        ]
    ]);
    
    $event = Event::factory()->create([
        'project_id' => $project->id,
        'name' => 'build.completed',
        'execution_id' => $execution->id,
        'payload' => ['status' => 'ok']
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('selectEvent', $event->id)
        ->assertSee('raw log')
        ->assertSee('diff')
        ->assertSee('build succeeded');
});

test('event from another project yields no detail block', function () {
    $project1 = Project::factory()->create();
    $project2 = Project::factory()->create();
    $event = Event::factory()->create([
        'project_id' => $project2->id,
        'payload' => ['reason' => 'other']
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project1])
        ->call('selectEvent', $event->id)
        ->assertDontSee('payload');
});
