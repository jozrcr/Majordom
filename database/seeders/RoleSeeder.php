<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'architect',
                'provider' => 'openrouter',
                'model' => config('majordom.architect.model'),
                'temperature' => config('majordom.architect.temperature'),
                'max_tokens' => config('majordom.architect.max_tokens'),
            ],
            [
                'name' => 'reviewer',
                'provider' => 'openrouter',
                'model' => config('majordom.reviewer.model'),
                'temperature' => config('majordom.reviewer.temperature'),
                'max_tokens' => config('majordom.reviewer.max_tokens'),
            ],
            [
                'name' => 'builder',
                'provider' => 'metallama',
                'model' => config('majordom.builder.gateway_model'),
                'meta' => ['managed_model' => config('majordom.builder.model')],
            ],
            // Builder Selection (M14b): the frontier model bound as a Builder,
            // selected per task (bootstrap / security / hard refactors). Distinct
            // from 'architect' (role separation) though it defaults to the same
            // model — bind it to claude / deepseek / glm here per project needs.
            [
                'name' => 'frontier_builder',
                'provider' => 'openrouter',
                'model' => config('majordom.frontier_builder.model'),
                'temperature' => config('majordom.frontier_builder.temperature'),
                'max_tokens' => config('majordom.frontier_builder.max_tokens'),
            ],
        ];

        foreach ($roles as $data) {
            Role::updateOrCreate(
                ['project_id' => null, 'name' => $data['name']],
                array_merge($data, ['is_builtin' => true])
            );
        }
    }
}
