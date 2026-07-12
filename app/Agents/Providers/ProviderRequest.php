<?php

namespace App\Agents\Providers;

final readonly class ProviderRequest
{
    public function __construct(
        public string $model,
        public array $messages,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public bool $jsonMode = false,
    ) {}
}
