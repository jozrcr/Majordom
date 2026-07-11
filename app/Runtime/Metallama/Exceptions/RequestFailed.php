<?php

namespace App\Runtime\Metallama\Exceptions;

class RequestFailed extends MetallamaException
{
    public function __construct(
        public readonly ?int $statusCode,
        string $message = ''
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}
