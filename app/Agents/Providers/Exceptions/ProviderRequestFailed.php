<?php

namespace App\Agents\Providers\Exceptions;

class ProviderRequestFailed extends ProviderException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null
    ) {
        parent::__construct($message);
    }
}
