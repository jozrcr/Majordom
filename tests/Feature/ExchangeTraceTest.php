<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Task;
use App\Models\UsageRecord;
use App\Projects\Exchanges\ExchangeTrace;
use Livewire\Livewire;
use Tests\TestCase;

class ExchangeTraceTest extends TestCase
{
    public function test_trace_maps_events_correctly(): void
    {
        $project = Project::create(['name' => 'test-proj', 'repo_path' => '/tmp/test']);
        $execution = Execution::create(['project_id' => $project->id, 'status' => \App\Enums\ExecutionStatus::Running]);
        $task = Task::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'task_key' => 'T-1',
            'title' => 'Test Task',
            'description' => 'Implement user authentication flow.',
        ]);

        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'delegate.started',
            'actor' => 'architect',
            'payload' => [],
        ]);

        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'build.completed',
            'actor' => 'builder',
            'payload' => ['summary' => 'Auth module built', 'filesChanged' => ['AuthController.php', 'LoginView.php']],
        ]);

        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'review.retry',
            'actor' => 'reviewer',
            'payload' => ['reason' => 'Missing error handling'],
        ]);

        Event::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'name' => 'review.completed',
            'actor' => 'reviewer',
            'payload' => ['verdict' => 'Approved'],
        ]);

        $trace = ExchangeTrace::for($execution);

        $this->assertCount(4, $trace);

        $this->assertEquals('architect', $trace[0]['from']);
        $this->assertEquals('builder', $trace[0]['to']);
        $this->assertEquals('instruction', $trace[0]['kind']);
        $this->assertEquals('Implement user authentication flow.', $trace[0]['full']);
        $this->assertLessThanOrEqual(201, strlen($trace[0]['excerpt']));

        $this->assertEquals('builder', $trace[1]['from']);
        $this->assertEquals('reviewer', $trace[1]['to']);
        $this->assertEquals('result', $trace[1]['kind']);
        $this->assertStringContainsString('Auth module built', $trace[1]['full']);
        $this->assertStringContainsString('AuthController.php', $trace[1]['full']);

        $this->assertEquals('reviewer', $trace[2]['from']);
        $this->assertEquals('builder', $trace[2]['to']);
        $this->assertEquals('rework', $trace[2]['kind']);
        $this->assertEquals('Missing error handling', $trace[2]['full']);

        $this->assertEquals('reviewer', $trace[3]['from']);
        $this->assertEquals('builder', $trace[3]['to']);
        $this->assertEquals('verdict', $trace[3]['kind']);
        $this->assertEquals('Approved', $trace[3]['full']);
    }

    public function test_instruction_deduplicated(): void
    {
        $project = Project::create(['name' => 'test-proj', 'repo_path' => '/tmp/test']);
        $execution = Execution::create(['project_id' => $project->id, 'status' => \App\Enums\ExecutionStatus::Running]);
        Task::create([
            'project_id' => $project->id,
            'execution_id' => $execution->id,
            'task_key' => 'T-1',
            'title' => 'Test',
            'description' => 'Task 1',
        ]);

        Event::create(['project_id' => $project->id, 'execution_id' => $execution->id, 'name' => 'task.delegated', 'actor' => 'architect', 'payload' => []]);
        Event::create(['project_id' => $project->id, 'execution_id' => $execution->id, 'name' => 'delegate.started', 'actor' => 'architect', 'payload' => []]);

        $trace = ExchangeTrace::for($execution);
        $instructions = array_filter($trace, fn($r) => $r['kind'] === 'instruction');
        $this->assertCount(1, $instructions);
    }

    public function test_usage_groups_by_role(): void
    {
        $project = Project::create(['name' => 'test-proj', 'repo_path' => '/tmp/test']);
        $execution = Execution::create(['project_id' => $project->id, 'status' => \App\Enums\ExecutionStatus::Running]);

        UsageRecord::create(['project_id' => $project->id, 'execution_id' => $execution->id, 'role' => 'architect', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'cost_usd' => 0.01]);
        UsageRecord::create(['project_id' => $project->id, 'execution_id' => $execution->id, 'role' => 'architect', 'prompt_tokens' => 200, 'completion_tokens' => 100, 'cost_usd' => 0.02]);
        UsageRecord::create(['project_id' => $project->id, 'execution_id' => $execution->id, 'role' => 'builder', 'prompt_tokens' => 500, 'completion_tokens' => 300, 'cost_usd' => 0.05]);

        $usage = ExchangeTrace::usageFor($execution);

        $this->assertArrayHasKey('architect', $usage);
        $this->assertEquals(300, $usage['architect']['prompt_tokens']);
        $this->assertEquals(150, $usage['architect']['completion_tokens']);
        $this->assertEquals(0.03, $usage['architect']['cost_usd']);

        $this->assertArrayHasKey('builder', $usage);
        $this->assertEquals(500, $usage['builder']['prompt_tokens']);
    }

    public function test_exchanges_tab_allowed_and_normalized(): void
    {
        $project = Project::create(['name' => 'test-proj', 'repo_path' => '/tmp/test']);

        Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
            ->set('tab', 'exchanges')
            ->assertSet('tab', 'exchanges');

        Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
            ->set('tab', 'unknown-tab')
            ->assertSet('tab', 'chat');
    }
}
