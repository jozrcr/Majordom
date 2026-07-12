<?php

namespace App\Enums;

enum ApprovalType: string
{
    case Review = 'review';
    case Commit = 'commit';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'review',
            self::Commit => 'commit',
        };
    }
}
