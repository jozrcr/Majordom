<?php

namespace Database\Factories;

use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsageRecordFactory extends Factory
{
    protected $model = UsageRecord::class;

    public function definition(): array
    {
        return [
            'role' => 'architect',
            'model' => 'test/model',
            'prompt_tokens' => $this->faker->numberBetween(100, 5000),
            'completion_tokens' => $this->faker->numberBetween(50, 2000),
            'cost_usd' => null,
        ];
    }
}
