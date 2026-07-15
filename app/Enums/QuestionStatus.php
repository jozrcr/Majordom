<?php

namespace App\Enums;

enum QuestionStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Discarded = 'discarded';
}
