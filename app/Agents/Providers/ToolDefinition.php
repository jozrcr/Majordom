<?php

namespace App\Agents\Providers;

/**
 * A tool the model may call, in one provider-agnostic shape (M15 tool contract).
 * `parameters` is a JSON Schema object describing the arguments. Each provider
 * client serializes this to its own wire format — mirroring ProviderResponse's
 * `fromOpenAi`, the serialization lives here as `toOpenAi()` (and a future
 * `toAnthropic()` sits beside it) so callers stay wire-agnostic.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed> $parameters JSON Schema (e.g. ['type' => 'object', 'properties' => [...], 'required' => [...]])
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}

    /** OpenAI / OpenAI-compatible function-tool wire shape. */
    public function toOpenAi(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }
}
