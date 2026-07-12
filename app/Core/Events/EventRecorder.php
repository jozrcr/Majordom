<?php

namespace App\Core\Events;

use App\Models\Event;
use App\Models\Execution;
use App\Models\Project;

class EventRecorder
{
    public function record(
        Project $project,
        string $name,
        array $payload = [],
        ?Execution $execution = null,
        string $actor = 'system'
    ): void {
        try {
            $event = Event::create([
                'project_id' => $project->id,
                'execution_id' => $execution?->id,
                'name' => $name,
                'actor' => $actor,
                'payload' => $payload,
            ]);

            broadcast(new DomainEventBroadcast([
                'id' => $event->id,
                'project_id' => $event->project_id,
                'execution_id' => $event->execution_id,
                'name' => $event->name,
                'actor' => $event->actor,
                'payload' => $event->payload,
                'created_at' => $event->created_at->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
