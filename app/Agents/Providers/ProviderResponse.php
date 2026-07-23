<?php

namespace App\Agents\Providers;

final readonly class ProviderResponse
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public string $content,
        public string $finishReason,
        public int $promptTokens,
        public int $completionTokens,
        public array $toolCalls = [],
    ) {}

    public static function fromOpenAi(array $json): self
    {
        $choice = $json['choices'][0] ?? null;
        $message = $choice['message'] ?? null;
        $usage = $json['usage'] ?? [];

        $toolCalls = [];
        foreach (($message['tool_calls'] ?? []) as $raw) {
            if (is_array($raw)) {
                $toolCalls[] = ToolCall::fromOpenAi($raw);
            }
        }

        return new self(
            content: $message['content'] ?? '', // null when the model only called tools
            finishReason: $choice['finish_reason'] ?? 'unknown',
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            toolCalls: $toolCalls,
        );
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
