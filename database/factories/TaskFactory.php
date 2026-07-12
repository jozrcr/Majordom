<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        $project = Project::factory();
        $execution = Execution::factory()->for($project, 'project');

        return [
            'project_id' => $project,
            'execution_id' => $execution,
            'task_key' => $this->faker->unique()->bothify('T-###'),
            'title' => $this->faker->sentence(),
            'branch' => null,
            'worktree_path' => null,
            'status' => 'pending',
            'revision' => 1,
        ];
    }
}
