<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Open = 'open';
    case Granted = 'granted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Granted => 'granted',
            self::Rejected => 'rejected',
        };
    }
}
