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
        public ?float $topP = null,
        public ?float $frequencyPenalty = null,
        public ?float $presencePenalty = null,
        public ?array $stop = null,
        public ?int $timeout = null,
        /** @var ToolDefinition[]|null Tools the model may call (M15). null ⇒ plain chat. */
        public ?array $tools = null,
        /** "auto" (default when tools set) | "required" | "none" | a specific tool name to force. */
        public ?string $toolChoice = null,
    ) {}
}
