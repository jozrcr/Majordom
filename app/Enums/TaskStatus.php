<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Building = 'building';
    case Testing = 'testing';
    case Reviewing = 'reviewing';
    case NeedsYou = 'needs_you';
    case Approved = 'approved';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Building => 'building',
            self::Testing => 'testing',
            self::Reviewing => 'reviewing',
            self::NeedsYou => 'needs you',
            self::Approved => 'approved',
            self::Failed => 'failed',
        };
    }
}
