<?php

use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessResult;
use App\Agents\Harness\HarnessStatus;
use App\Core\Usage\SpendGuard;
use App\Core\Workflow\Nodes\BuildNode;
use App\Enums\ImplementationStrategy;
use App\Models\Event;
use App\Models\UsageRecord;
use App\Projects\Memory\MemoryStore;
use App\Runtime\Metallama\ModelState;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Runtime\Metallama\ServerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

class SgFakeHarness implements Harness
{
    public array $requests = [];

    public function __construct(public HarnessResult $result) {}

    public function runTask(HarnessRequest $request): HarnessResult
    {
        $this->requests[] = $request;

        return $this->result;
    }
}

class SgFakeCoordinator extends ResourceCoordinator
{
    public function __construct() {}

    public function ensure(string $id): ModelState
    {
        return new ModelState(id: $id, status: ServerStatus::Online);
    }
}

function sgResult(): HarnessResult
{
    return new HarnessResult(HarnessStatus::Completed, 'diff', ['a.php'], true, 'Done', [], 'log');
}

it('capForRole reads config with a Setting override; unlisted roles are uncapped', function () {
    Config::set('majordom.workflow.role_spend_caps', ['frontier_builder' => 1.0]);

    expect(SpendGuard::capForRole('frontier_builder'))->toBe(1.0)
        ->and(SpendGuard::capForRole('reviewer'))->toBeNull(); // cheap, uncapped

    \App\Support\Setting::put('workflow.role_spend_caps.frontier_builder', 0.25);
    expect(SpendGuard::capForRole('frontier_builder'))->toBe(0.25);
});

it('flatCapParks: full_auto keeps moving, other profiles park', function () {
    [$e1] = createExecutionWithTask([], []);
    $e1->update(['profile' => 'full_auto']);
    [$e2] = createExecutionWithTask([], []);
    $e2->update(['profile' => 'overnight']);

    expect(SpendGuard::flatCapParks($e1))->toBeFalse()
        ->and(SpendGuard::flatCapParks($e2))->toBeTrue();
});

it('mustBuildLocal: frontier task downgrades when its role cap is blown; local never does', function () {
    Config::set('majordom.workflow.role_spend_caps', ['frontier_builder' => 0.10]);
    [$execution, $task] = createExecutionWithTask(['implementation_strategy' => ImplementationStrategy::Frontier]);
    UsageRecord::create(['project_id' => $task->project_id, 'execution_id' => $execution->id, 'role' => 'frontier_builder', 'model' => 'x', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'cost_usd' => 0.50]);

    expect(SpendGuard::mustBuildLocal($execution, $task))->toBeTrue();

    $task->update(['implementation_strategy' => ImplementationStrategy::Local]);
    expect(SpendGuard::mustBuildLocal($execution->fresh(), $task->fresh()))->toBeFalse();
});

it('mustBuildLocal: full_auto over the flat cap downgrades a frontier build', function () {
    Config::set('majordom.workflow.role_spend_caps', []); // no per-role cap
    [$execution, $task] = createExecutionWithTask(['implementation_strategy' => ImplementationStrategy::Frontier]);
    $execution->update(['profile' => 'full_auto', 'spend_cap_usd' => 0.10]);
    UsageRecord::create(['project_id' => $task->project_id, 'execution_id' => $execution->id, 'role' => 'reviewer', 'model' => 'x', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'cost_usd' => 0.50]);

    expect(SpendGuard::mustBuildLocal($execution->fresh(), $task))->toBeTrue();

    // attended, same overspend, per-role uncapped → NOT downgraded (it parks instead, upstream)
    $execution->update(['profile' => 'attended']);
    expect(SpendGuard::mustBuildLocal($execution->fresh(), $task))->toBeFalse();
});

it('BuildNode: a frontier build over budget runs on the local Builder and does not park (full_auto)', function () {
    setupMemoryRoot();
    Config::set('majordom.workflow.role_spend_caps', ['frontier_builder' => 0.10]);
    Config::set('majordom.builder.gateway_model', 'qwen-local');
    Config::set('queue.connections.harness.driver', 'sync');

    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/wt',
        'implementation_strategy' => ImplementationStrategy::Frontier,
    ]);
    $execution->update(['profile' => 'full_auto', 'spend_cap_usd' => 0.10]);
    UsageRecord::create(['project_id' => $project->id, 'execution_id' => $execution->id, 'role' => 'frontier_builder', 'model' => 'x', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'cost_usd' => 0.50]);
    app(MemoryStore::class)->write($project, "tasks/{$task->task_key}/task.md", 'Task');

    $harness = new SgFakeHarness(sgResult());
    app()->instance(Harness::class, $harness);
    app()->instance(ResourceCoordinator::class, new SgFakeCoordinator());

    (new BuildNode($node->id))->handle();

    expect($harness->requests)->toHaveCount(1) // ran (not parked by the flat cap)
        ->and($harness->requests[0]->modelName)->toBe('qwen-local') // downgraded to local
        ->and($execution->fresh()->status)->not->toBe(\App\Enums\ExecutionStatus::Parked)
        ->and(Event::where('project_id', $project->id)->where('name', 'build.builder_downgraded')->count())->toBe(1);
});
