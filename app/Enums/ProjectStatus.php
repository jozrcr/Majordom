<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Idle = 'idle';
    case Working = 'working';
    case NeedsYou = 'needs_you';
    case Parked = 'parked';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'idle',
            self::Working => 'working',
            self::NeedsYou => 'needs you',
            self::Parked => 'parked',
        };
    }
}
