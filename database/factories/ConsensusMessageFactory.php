<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\ConsensusMessage;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsensusMessageFactory extends Factory
{
    protected $model = ConsensusMessage::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'role' => MessageRole::Architect,
            'content' => $this->faker->sentence(),
            'meta' => null,
        ];
    }
}
