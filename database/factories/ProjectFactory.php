<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);
        $slug = Str::slug($name) . '-' . random_int(1000, 9999);

        return [
            'name' => $name,
            'slug' => $slug,
            'repo_path' => '/tmp/repos/' . $slug,
            'memory_path' => null,
            'status' => ProjectStatus::Idle,
            'last_activity_at' => null,
        ];
    }
}
