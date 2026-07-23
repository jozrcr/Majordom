<?php

namespace App\Agents\Providers;

/**
 * Builds the two tool-turn message kinds a tool loop appends to `messages`
 * (M15 tool contract). The internal canonical message shape is OpenAI-shaped
 * (the v1 frontier providers speak it natively); a future AnthropicProvider
 * translates the whole messages array at its boundary. Keeping the wire shape
 * here means the loop (ArchitectService) never hand-rolls it.
 */
final class ToolMessages
{
    /** The assistant turn that issued tool calls, replayed in the next request. */
    public static function assistantToolCalls(ProviderResponse $response): array
    {
        return [
            'role' => 'assistant',
            // content may be null when the model only called tools.
            'content' => $response->content !== '' ? $response->content : null,
            'tool_calls' => array_map(fn (ToolCall $c) => $c->toOpenAi(), $response->toolCalls),
        ];
    }

    /** A tool's result, fed back to the model on the next turn. */
    public static function toolResult(string $toolCallId, string $content): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => $content,
        ];
    }
}
