<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Role;
use InvalidArgumentException;

final readonly class RoleBinding
{
    public function __construct(
        public string $name,
        public string $provider,
        public string $model,
        public array $meta = [],
        public ?float $temperature = null,
        public ?int $maxTokens = null,
    ) {}
}

class RoleResolver
{
    public function resolve(string $name, ?Project $project = null): RoleBinding
    {
        // 1. Project override
        if ($project) {
            $role = Role::where('project_id', $project->id)
                ->where('name', $name)
                ->first();
            if ($role) {
                return $this->toBinding($role);
            }
        }

        // 2. Global row
        $role = Role::whereNull('project_id')
            ->where('name', $name)
            ->first();
        if ($role) {
            return $this->toBinding($role);
        }

        // 3. Built-in config fallback
        return $this->fallback($name);
    }

    private function toBinding(Role $role): RoleBinding
    {
        return new RoleBinding(
            name: $role->name,
            provider: $role->provider,
            model: $role->model,
            meta: $role->meta ?? [],
            temperature: $role->temperature,
            maxTokens: $role->max_tokens ? (int) $role->max_tokens : null,
        );
    }

    private function fallback(string $name): RoleBinding
    {
        return match ($name) {
            'architect' => new RoleBinding(
                name: 'architect',
                provider: 'openrouter',
                model: (string) config('majordom.architect.model'),
                temperature: config('majordom.architect.temperature'),
                maxTokens: config('majordom.architect.max_tokens'),
            ),
            'reviewer' => new RoleBinding(
                name: 'reviewer',
                provider: 'openrouter',
                model: (string) config('majordom.reviewer.model'),
                temperature: config('majordom.reviewer.temperature'),
                maxTokens: config('majordom.reviewer.max_tokens'),
            ),
            'builder' => new RoleBinding(
                name: 'builder',
                provider: 'metallama',
                model: (string) config('majordom.builder.gateway_model'),
                meta: ['managed_model' => config('majordom.builder.model')],
            ),
            default => throw new InvalidArgumentException("Unknown role: {$name}"),
        };
    }
}
