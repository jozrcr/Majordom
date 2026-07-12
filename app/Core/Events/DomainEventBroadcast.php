<?php

namespace App\Core\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomainEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $event)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('project.'.$this->event['project_id']);
    }

    public function broadcastAs(): string
    {
        return 'domain-event';
    }

    public function broadcastWith(): array
    {
        return $this->event;
    }
}
