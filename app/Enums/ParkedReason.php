<?php

namespace App\Enums;

enum ParkedReason: string
{
    case Budget = 'budget';
    case ReworkLimit = 'rework_limit';
    case HarnessFailure = 'harness_failure';
    case DecomposeFailure = 'decompose_failure';
    case OwnerPause = 'owner_pause';

    /** 'park' = owner input can wait; 'escalate' = loop cannot proceed sanely. */
    public function classification(): string
    {
        return match ($this) {
            self::Budget, self::OwnerPause => 'park',
            self::ReworkLimit, self::HarnessFailure, self::DecomposeFailure => 'escalate',
        };
    }
}
