<?php

namespace Tests\Feature;

use App\Livewire\ProjectWorkspace;
use App\Models\Execution;
use App\Models\Project;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_tab_is_chat(): void
    {
        $project = Project::factory()->create();

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->assertSet('tab', 'chat');
    }

    public function test_overview_tab_renders_project_facts(): void
    {
        $project = Project::factory()->create(['repo_path' => 'github.com/test/repo']);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->set('tab', 'overview')
            ->assertSee('github.com/test/repo');
    }

    public function test_stats_tab_renders_usage_totals(): void
    {
        $project = Project::factory()->create();

        UsageRecord::create([
            'project_id' => $project->id,
            'role' => 'architect',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cost_usd' => 0.005,
        ]);

        UsageRecord::create([
            'project_id' => $project->id,
            'role' => 'builder',
            'prompt_tokens' => 200,
            'completion_tokens' => 100,
            'cost_usd' => 0.010,
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->set('tab', 'stats')
            ->assertSee('$0.0150');
    }

    public function test_invalid_tab_falls_back_to_chat(): void
    {
        $project = Project::factory()->create();

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->set('tab', 'zzz')
            ->assertSet('tab', 'chat');
    }
}
