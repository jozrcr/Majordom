<?php

namespace Database\Factories;

use App\Enums\QuestionStatus;
use App\Models\Project;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'consensus_message_id' => null,
            'text' => $this->faker->sentence(),
            'options' => null,
            'status' => QuestionStatus::Open,
            'answer' => null,
            'answered_at' => null,
        ];
    }
}
