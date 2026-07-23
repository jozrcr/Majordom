<?php

namespace App\Agents\Providers;

/**
 * A single tool call the model emitted, normalized (M15 tool contract). The loop
 * dispatches on `name` and reads `arguments` (already decoded to an assoc array);
 * `toOpenAi()` rebuilds the wire shape so the assistant turn can be replayed in
 * the next request.
 */
final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}

    public static function fromOpenAi(array $raw): self
    {
        $fn = $raw['function'] ?? [];

        // OpenAI returns arguments as a JSON string; decode it defensively. A
        // schema-constrained tool rarely returns malformed JSON, but never let a
        // bad decode become a fatal — an empty arg map surfaces as "tool called
        // with no args" the loop can handle, not a parse crash.
        $args = [];
        $rawArgs = $fn['arguments'] ?? null;
        if (is_string($rawArgs) && trim($rawArgs) !== '') {
            $decoded = json_decode($rawArgs, true);
            $args = is_array($decoded) ? $decoded : [];
        } elseif (is_array($rawArgs)) {
            $args = $rawArgs;
        }

        return new self(
            id: (string) ($raw['id'] ?? ''),
            name: (string) ($fn['name'] ?? ''),
            arguments: $args,
        );
    }

    /** Rebuild the OpenAI wire shape for replaying the assistant turn. */
    public function toOpenAi(): array
    {
        return [
            'id' => $this->id,
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'arguments' => json_encode($this->arguments),
            ],
        ];
    }
}
