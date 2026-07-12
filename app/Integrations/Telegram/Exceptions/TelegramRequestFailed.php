<?php

namespace App\Integrations\Telegram\Exceptions;

class TelegramRequestFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $errorCode)
    {
        parent::__construct($message);
    }
}
