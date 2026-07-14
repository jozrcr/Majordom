<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Execution;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\UsageRecord;
use App\Projects\Metrics\MilestoneMetrics;
use Livewire\Livewire;
use Tests\TestCase;

class MilestoneMetricsTest extends TestCase
{
    public function test_for_milestone_aggregates_metrics(): void
    {
        $project = Project::factory()->create();
        $milestone = Milestone::factory()->create(['project_id' => $project->id]);
        
        $execution = Execution::factory()->create(['project_id' => $project->id]);
        
        $task1 = Task::factory()->create([
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
            'execution_id' => $execution->id,
            'revision' => 2,
        ]);
        
        $task2 = Task::factory()->create([
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
            'execution_id' => null,
        ]);

        // Usage records
        UsageRecord::factory()->create(['execution_id' => $execution->id, 'role' => 'architect', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'cost_usd' => 0.1]);
        UsageRecord::factory()->create(['execution_id' => $execution->id, 'role' => 'builder', 'prompt_tokens' => 200, 'completion_tokens' => 100, 'cost_usd' => 0.2]);
        UsageRecord::factory()->create(['execution_id' => $execution->id, 'role' => 'reviewer', 'prompt_tokens' => 50, 'completion_tokens' => 25, 'cost_usd' => 0.05]);

        // Events
        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'build.completed',
            'actor' => 'system',
            'payload' => ['filesChanged' => ['a.php', 'b.php']],
            'created_at' => now()->subMinutes(10),
        ]);
        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'review.retry',
            'actor' => 'reviewer',
            'payload' => [],
            'created_at' => now()->subMinutes(5),
        ]);
        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'human_review.waiting_human',
            'actor' => 'system',
            'payload' => [],
            'created_at' => now(),
        ]);

        $metrics = MilestoneMetrics::forMilestone($milestone);

        $this->assertEquals(150, $metrics['tokens']['architect']);
        $this->assertEquals(300, $metrics['tokens']['builder']);
        $this->assertEquals(75, $metrics['tokens']['reviewer']);
        $this->assertEquals(0.35, $metrics['cost_usd']);
        $this->assertEquals(1, $metrics['human_interventions']);
        $this->assertEquals(1, $metrics['rework_cycles']);
        $this->assertEquals(2, $metrics['files_changed']);
        $this->assertNotNull($metrics['time_to_completion']);
        $this->assertNull($metrics['tests_added']);
    }

    public function test_for_task_matches_and_never_ran_is_zero(): void
    {
        $project = Project::factory()->create();
        $milestone = Milestone::factory()->create(['project_id' => $project->id]);
        $execution = Execution::factory()->create(['project_id' => $project->id]);

        $task1 = Task::factory()->create([
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
            'execution_id' => $execution->id,
            'revision' => 3,
        ]);

        $task2 = Task::factory()->create([
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
            'execution_id' => null,
        ]);

        UsageRecord::factory()->create(['execution_id' => $execution->id, 'role' => 'builder', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'cost_usd' => 0.1]);
        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'build.completed',
            'actor' => 'system',
            'payload' => ['filesChanged' => ['x.php']],
            'created_at' => now()->subHour(),
        ]);
        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'build.completed',
            'actor' => 'system',
            'payload' => [],
            'created_at' => now(),
        ]);

        $m1 = MilestoneMetrics::forTask($task1);
        $this->assertEquals(150, $m1['tokens']['builder']);
        $this->assertEquals(0.1, $m1['cost_usd']);
        $this->assertEquals(1, $m1['files_changed']);
        $this->assertEquals(2, $m1['rework_cycles']); // max(0, 3-1) = 2
        $this->assertNotNull($m1['time_to_completion']);

        $m2 = MilestoneMetrics::forTask($task2);
        $this->assertEquals(0, $m2['tokens']['builder']);
        $this->assertEquals(0.0, $m2['cost_usd']);
        $this->assertEquals(0, $m2['files_changed']);
        $this->assertEquals(0, $m2['rework_cycles']);
        $this->assertNull($m2['time_to_completion']);
    }

    public function test_livewire_milestone_metrics_property(): void
    {
        $project = Project::factory()->create();
        $milestone = Milestone::factory()->create(['project_id' => $project->id]);
        Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id]);

        $component = Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project]);
        $metrics = $component->get('milestoneMetrics');
        
        $this->assertCount(1, $metrics);
        $this->assertEquals($milestone->milestone_key, $metrics[0]['key']);
        $this->assertEquals($milestone->title, $metrics[0]['title']);
        $this->assertArrayHasKey('metrics', $metrics[0]);
    }
}
