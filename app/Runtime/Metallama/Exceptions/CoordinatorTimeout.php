<?php

namespace App\Runtime\Metallama\Exceptions;

use App\Runtime\Metallama\ModelState;

class CoordinatorTimeout extends MetallamaException
{
    public function __construct(string $message, public readonly ?ModelState $lastState = null)
    {
        parent::__construct($message);
    }
}
