<?php

namespace Database\Factories;

use App\Models\ProviderEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderEndpointFactory extends Factory
{
    protected $model = ProviderEndpoint::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(),
            'label' => $this->faker->word(),
            'driver' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:11434/v1',
            'api_key' => null,
            'timeout' => 120,
            'meta' => null,
            'is_builtin' => false,
        ];
    }
}
