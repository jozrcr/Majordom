<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Core\Workflow\WorkflowEngine;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class RedefineScriptedProvider implements Provider
{
    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        return new ProviderResponse(array_shift($this->responses) ?? '{}', 'stop', 2, 2);
    }
}

it('closes a stuck execution and re-arms mid-flight tasks, sparing the done ones', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create([
        'project_id' => $project->id,
        'status' => ExecutionStatus::Parked,
        'meta' => ['parked_reason' => 'rework', 'parked_reason_class' => 'rework_limit'],
    ]);
    Node::factory()->create(['execution_id' => $exec->id, 'type' => 'review', 'status' => NodeStatus::Failed]);
    Node::factory()->create(['execution_id' => $exec->id, 'type' => 'build', 'status' => NodeStatus::Pending]);
    $midFlight = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Building]);
    $done = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Approved]);

    app(WorkflowEngine::class)->resetForRedefine($project);

    $exec->refresh();
    expect($exec->status)->toBe(ExecutionStatus::Completed)
        ->and($exec->meta['superseded_by_redefine'] ?? false)->toBeTrue()
        ->and($exec->meta['parked_reason'] ?? null)->toBeNull()
        ->and($midFlight->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($done->fresh()->status)->toBe(TaskStatus::Approved) // immutable past untouched
        ->and($project->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($project->events()->where('name', 'plan.redefine_reset')->count())->toBe(1);
});

it('is safe when there is no execution', function () {
    $project = Project::factory()->create();

    app(WorkflowEngine::class)->resetForRedefine($project);

    expect($project->events()->where('name', 'plan.redefine_reset')->count())->toBe(1)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Idle);
});

it('re-arms Start build with the revised first pending task and a FRESH brief', function () {
    // BUG 2 (M12Bis): redefine reset the loop but set no firstTaskId, so no
    // "Start build" appeared — and DelegateNode never re-decomposes, so even a
    // restart would rebuild the stale (poisoned) brief. Both halves fixed here.
    config(['majordom.memory_root' => sys_get_temp_dir().'/mj-redef2-'.uniqid()]);
    $project = Project::factory()->create();
    $milestone = \App\Models\Milestone::factory()->create([
        'project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id,
        'task_key' => 'T-001', 'status' => TaskStatus::Failed, 'position' => 1,
    ]);

    $memory = MemoryStore::fromConfig();
    $memory->write($project, 'tasks/T-001/task.md', '# OLD poisoned brief');

    app()->instance(Provider::class, new RedefineScriptedProvider([
        json_encode(['roadmap_md' => "## M1 — Skeleton\nDo it.\n- [ ] T-001 — Do the thing (revised)\n", 'summary' => 'revised']),
        '# FRESH brief from the revised roadmap', // decompose reply (markdown, jsonMode false)
    ]));
    $service = new ArchitectService(app(ProviderRegistry::class), $memory, app(RepoIndex::class));

    $service->redefinePlan($project, 'revise T-001');

    $lastSystem = $project->consensusMessages()
        ->where('role', \App\Enums\MessageRole::System)->orderByDesc('id')->first();

    expect($lastSystem->meta['planWritten'] ?? false)->toBeTrue()
        ->and($lastSystem->meta['firstTaskId'] ?? null)->toBe('T-001')       // Start build re-armed
        ->and($task->fresh()->status)->toBe(TaskStatus::Pending)             // re-armed to Pending
        ->and(trim((string) $memory->read($project, 'tasks/T-001/task.md'))) // brief regenerated
            ->toBe('# FRESH brief from the revised roadmap');
});

it('redefinePlan resets the loop after a valid revision', function () {
    config(['majordom.memory_root' => sys_get_temp_dir().'/mj-redef-'.uniqid()]);
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Parked]);

    app()->instance(Provider::class, new RedefineScriptedProvider([
        json_encode(['roadmap_md' => "## M1\n- [ ] T-001 — Do the thing\n", 'summary' => 'revised']),
    ]));
    $service = new ArchitectService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class));

    $service->redefinePlan($project, 'restructure the milestones');

    expect($project->events()->where('name', 'plan.redefine_reset')->count())->toBe(1)
        ->and($exec->fresh()->status)->toBe(ExecutionStatus::Completed);
});
