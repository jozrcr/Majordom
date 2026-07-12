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
            Event::create([
                'project_id' => $project->id,
                'execution_id' => $execution?->id,
                'name' => $name,
                'actor' => $actor,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
