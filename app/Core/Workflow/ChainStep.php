<?php

namespace App\Core\Workflow;

final readonly class ChainStep
{
    public function __construct(
        public string $type,
        public string $role,
        public array $config = [],
    ) {}

    public static function normalize(array $chain): array
    {
        $defaults = [
            'build' => 'builder',
            'review' => 'reviewer',
        ];

        return array_map(function ($step) use ($defaults) {
            if (is_string($step)) {
                return new self(
                    type: $step,
                    role: $defaults[$step] ?? 'system',
                    config: [],
                );
            }

            if (is_array($step)) {
                return new self(
                    type: $step['type'] ?? throw new \InvalidArgumentException('Chain step must have a type'),
                    role: $step['role'] ?? $defaults[$step['type'] ?? ''] ?? 'system',
                    config: $step['config'] ?? [],
                );
            }

            throw new \InvalidArgumentException('Invalid chain step format.');
        }, $chain);
    }

    public static function toStorable(array $steps): array
    {
        return array_map(fn (self $step) => [
            'type' => $step->type,
            'role' => $step->role,
            'config' => $step->config,
        ], $steps);
    }
}
