<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Open = 'open';
    case Granted = 'granted';
    case Rejected = 'rejected';
    /** M16-A: a milestone merge the owner set aside — branch/worktree kept
     *  intact, re-openable later. Out of the "Needs You" inbox, but not closed. */
    case Deferred = 'deferred';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Granted => 'granted',
            self::Rejected => 'rejected',
            self::Deferred => 'deferred',
        };
    }
}
