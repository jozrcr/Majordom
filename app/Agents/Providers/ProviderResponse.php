<?php

namespace App\Agents\Providers;

final readonly class ProviderResponse
{
    public function __construct(
        public string $content,
        public string $finishReason,
        public int $promptTokens,
        public int $completionTokens,
    ) {}

    public static function fromOpenAi(array $json): self
    {
        $choice = $json['choices'][0] ?? null;
        $message = $choice['message'] ?? null;
        $usage = $json['usage'] ?? [];

        return new self(
            content: $message['content'] ?? '',
            finishReason: $choice['finish_reason'] ?? 'unknown',
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
        );
    }
}
