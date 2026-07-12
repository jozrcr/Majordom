<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case Running = 'running';
    case NeedsYou = 'needs_you';
    case Completed = 'completed';
    case Parked = 'parked';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'running',
            self::NeedsYou => 'needs you',
            self::Completed => 'completed',
            self::Parked => 'parked',
        };
    }
}
