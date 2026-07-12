<?php

namespace Database\Factories;

use App\Models\CommitSuggestion;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommitSuggestion> */
class CommitSuggestionFactory extends Factory
{
    protected $model = CommitSuggestion::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'message' => "feat: ".fake()->sentence(4)."\n\nBody.",
            'diff' => 'diff --git a/x b/x',
            'branch' => 'majordom/T-001',
            'status' => 'suggested',
        ];
    }
}
