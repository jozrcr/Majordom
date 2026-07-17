<?php

use App\Core\Workflow\EscalationRouter;
use App\Enums\ParkedReason;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('park() stores reason class in meta', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id]);
    
    $exec->park('over budget', ParkedReason::Budget);
    
    $exec->refresh();
    expect($exec->meta['parked_reason'])->toBe('over budget')
        ->and($exec->meta['parked_reason_class'])->toBe('budget');
});

test('budget park under attended emits run.parked', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'profile' => 'attended']);
    
    app(EscalationRouter::class)->route($exec, ParkedReason::Budget, 'x');
    
    expect(Event::where('name', 'run.parked')->first())->not->toBeNull()
        ->and(Event::where('name', 'run.escalated')->count())->toBe(0);
        
    $event = Event::where('name', 'run.parked')->first();
    expect($event->payload['class'])->toBe('budget');
});

test('rework limit emits run.escalated', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'profile' => 'attended']);
    
    app(EscalationRouter::class)->route($exec, ParkedReason::ReworkLimit, 'x');
    
    expect(Event::where('name', 'run.escalated')->first())->not->toBeNull();
});

test('full_auto park-class escalates with full_auto_stop', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'profile' => 'full_auto']);
    
    app(EscalationRouter::class)->route($exec, ParkedReason::Budget, 'x');
    
    $event = Event::where('name', 'run.escalated')->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['full_auto_stop'])->toBeTrue()
        ->and($event->payload['class'])->toBe('budget');
    expect(Event::where('name', 'run.parked')->count())->toBe(0);
});

test('owner_pause under full_auto stays run.parked', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'profile' => 'full_auto']);
    
    app(EscalationRouter::class)->route($exec, ParkedReason::OwnerPause, 'x');
    
    expect(Event::where('name', 'run.parked')->first())->not->toBeNull()
        ->and(Event::where('name', 'run.escalated')->count())->toBe(0);
});
