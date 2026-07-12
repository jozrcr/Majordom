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
        ];

        foreach ($roles as $data) {
            Role::updateOrCreate(
                ['project_id' => null, 'name' => $data['name']],
                array_merge($data, ['is_builtin' => true])
            );
        }
    }
}
