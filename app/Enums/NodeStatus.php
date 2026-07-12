<?php

namespace App\Enums;

enum NodeStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case WaitingHuman = 'waiting_human';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Running => 'running',
            self::Completed => 'completed',
            self::Failed => 'failed',
            self::WaitingHuman => 'waiting_human',
        };
    }
}
