<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'execution_id' => null,
            'name' => $this->faker->word,
            'actor' => 'system',
            'payload' => [],
            'created_at' => now(),
        ];
    }
}
