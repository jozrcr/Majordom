<?php

use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessResult;
use App\Agents\Harness\HarnessStatus;
use App\Core\Workflow\BuilderSelector;
use App\Core\Workflow\Nodes\BuildNode;
use App\Enums\ImplementationStrategy;
use App\Models\Event;
use App\Projects\Memory\MemoryStore;
use App\Runtime\Metallama\ModelState;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Runtime\Metallama\ServerStatus;
use App\Support\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

// Uniquely-named fakes (Pest test-file classes share a global scope across the
// suite — avoid colliding with PipelineNodesTest's FakeHarness/FakeCoordinator).
class BsFakeHarness implements Harness
{
    public array $requests = [];

    public function __construct(public HarnessResult $result) {}

    public function runTask(HarnessRequest $request): HarnessResult
    {
        $this->requests[] = $request;

        return $this->result;
    }
}

class BsFakeCoordinator extends ResourceCoordinator
{
    public array $ensured = [];

    public function __construct() {}

    public function ensure(string $id): ModelState
    {
        $this->ensured[] = $id;

        return new ModelState(id: $id, status: ServerStatus::Online);
    }
}

function buildResult(): HarnessResult
{
    return new HarnessResult(
        status: HarnessStatus::Completed,
        diff: 'diff --git',
        filesChanged: ['a.php'],
        testsPassed: true,
        summary: 'Done',
        openQuestions: [],
        rawLog: 'log'
    );
}

it('maps strategies to builder roles and defaults null to local', function () {
    expect(ImplementationStrategy::Local->builderRole())->toBe('builder')
        ->and(ImplementationStrategy::Frontier->builderRole())->toBe('frontier_builder')
        ->and(ImplementationStrategy::fromValue(null))->toBe(ImplementationStrategy::Local)
        ->and(ImplementationStrategy::fromValue('garbage'))->toBe(ImplementationStrategy::Local);
});

it('resolves the frontier_builder role to the OpenRouter binding (distinct from architect)', function () {
    Config::set('majordom.frontier_builder.model', 'anthropic/claude-x');

    $binding = app(RoleResolver::class)->resolve('frontier_builder');

    expect($binding->provider)->toBe('openrouter')
        ->and($binding->model)->toBe('anthropic/claude-x')
        ->and($binding->name)->toBe('frontier_builder'); // NOT 'architect'
});

it('a task defaults to Local and BuildNode routes it to the metallama builder', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask(['worktree_path' => '/tmp/wt']);

    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/task.md", 'Task prompt');

    Config::set('majordom.builder.gateway_model', 'qwen-local');
    Config::set('queue.connections.harness.driver', 'sync');

    $harness = new BsFakeHarness(buildResult());
    app()->instance(Harness::class, $harness);
    app()->instance(ResourceCoordinator::class, new BsFakeCoordinator());

    expect($task->strategy())->toBe(ImplementationStrategy::Local);

    (new BuildNode($node->id))->handle();

    expect($harness->requests[0]->modelName)->toBe('qwen-local')
        ->and($harness->requests[0]->endpointBaseUrl)->toEndWith('/ollama/v1'); // metallama gateway
});

it('a frontier task routes BuildNode to the frontier binding and records the actor', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask(['worktree_path' => '/tmp/wt']);
    $task->update(['implementation_strategy' => ImplementationStrategy::Frontier]);

    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/task.md", 'Task prompt');

    Config::set('majordom.frontier_builder.model', 'anthropic/claude-x');
    Config::set('queue.connections.harness.driver', 'sync');

    $harness = new BsFakeHarness(buildResult());
    app()->instance(Harness::class, $harness);
    $coordinator = new BsFakeCoordinator();
    app()->instance(ResourceCoordinator::class, $coordinator);

    (new BuildNode($node->id))->handle();

    expect($harness->requests[0]->modelName)->toBe('anthropic/claude-x')
        ->and($harness->requests[0]->endpointBaseUrl)->toContain('openrouter.ai') // frontier endpoint
        ->and($coordinator->ensured)->toBe([]) // never loads a local model for a frontier build
        ->and(Event::where('project_id', $project->id)->where('name', 'build.builder_selected')->get()
            ->contains(fn ($e) => ($e->payload['role'] ?? null) === 'frontier_builder'))->toBeTrue();
});

it('BuilderSelector assigns a strategy and emits one selection event, idempotently', function () {
    [$execution, $task] = createExecutionWithTask();

    app(BuilderSelector::class)->assign($task, ImplementationStrategy::Frontier, 'you');
    app(BuilderSelector::class)->assign($task, ImplementationStrategy::Frontier, 'you'); // no-op

    expect($task->fresh()->strategy())->toBe(ImplementationStrategy::Frontier)
        ->and(Event::where('project_id', $task->project_id)->where('name', 'task.builder_selected')->count())->toBe(1);
});
