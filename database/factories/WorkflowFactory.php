<?php

namespace Database\Factories;

use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'chain' => ['delegate', 'build', 'test', 'review', 'commit_suggestion'],
            'is_builtin' => false,
        ];
    }
}
