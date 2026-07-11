<?php

namespace App\Runtime\Metallama;

enum ServerStatus: string
{
    case Offline = 'offline';
    case Starting = 'starting';
    case Online = 'online';
}
