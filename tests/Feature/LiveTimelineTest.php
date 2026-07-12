<?php

use App\Core\Events\DomainEventBroadcast;
use App\Core\Events\EventRecorder;
use App\Models\Event;
use App\Models\Project;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('domain event broadcast is dispatched on record', function () {
    EventFacade::fake([DomainEventBroadcast::class]);

    $project = Project::factory()->create();
    $recorder = app(EventRecorder::class);

    $recorder->record($project, 'test.event', ['key' => 'value'], null, 'system');

    EventFacade::assertDispatched(function (DomainEventBroadcast $event) use ($project) {
        return $event->event['project_id'] === $project->id
            && $event->event['name'] === 'test.event'
            && $event->broadcastOn()->name === "project.{$project->id}";
    });
});

test('workspace renders timeline rows', function () {
    $project = Project::factory()->create();
    Event::create(['project_id' => $project->id, 'name' => 'task.started', 'actor' => 'system', 'payload' => []]);
    Event::create(['project_id' => $project->id, 'name' => 'review.waiting_human', 'actor' => 'reviewer', 'payload' => []]);

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee('task.started')
        ->assertSee('review.waiting_human')
        ->assertSee('Activity');
});

test('workspace shows no activity yet with zero events', function () {
    $project = Project::factory()->create();

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee('no activity yet');
});
