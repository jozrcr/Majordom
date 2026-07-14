<?php

namespace Database\Factories;

use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    protected $model = Milestone::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'milestone_key' => $this->faker->unique()->bothify('M##'),
            'title' => $this->faker->sentence(3),
            'summary' => $this->faker->sentence(),
            'position' => $this->faker->numberBetween(1, 20),
        ];
    }
}
