<?php

namespace App\Agents\Harness;

enum HarnessStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case NeedsHuman = 'needs_human';
}
