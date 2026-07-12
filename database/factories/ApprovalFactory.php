<?php

namespace Database\Factories;

use App\Models\Approval;
use App\Models\Execution;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    public function definition(): array
    {
        $project = Project::factory();
        $execution = Execution::factory()->for($project, 'project');

        return [
            'project_id' => $project,
            'execution_id' => $execution,
            'type' => 'review',
            'title' => $this->faker->sentence(),
            'payload' => null,
            'status' => 'open',
            'resolved_at' => null,
        ];
    }
}
