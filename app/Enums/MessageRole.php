<?php

namespace App\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Architect = 'architect';
    case System = 'system';
}
