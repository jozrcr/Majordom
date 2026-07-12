<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Execution>
 */
class ExecutionFactory extends Factory
{
    protected $model = Execution::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'status' => 'running',
            'current_node' => null,
            'meta' => null,
        ];
    }
}
