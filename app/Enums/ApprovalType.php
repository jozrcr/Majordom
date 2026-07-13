<?php

namespace App\Enums;

enum ApprovalType: string
{
    case Review = 'review';
    case Commit = 'commit';
    case HumanTask = 'human_task';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'review',
            self::Commit => 'commit',
            self::HumanTask => 'human_task',
        };
    }
}
