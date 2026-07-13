<?php

use App\Core\Workflow\ChainStep;
use App\Core\Workflow\WorkflowEngine;
use App\Core\Workflow\Nodes\BuildNode;
use App\Core\Workflow\Nodes\ReviewNode;
use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessResult;
use App\Agents\Harness\HarnessStatus;
use App\Agents\Reviewer\ReviewerService;
use App\Agents\Reviewer\ReviewVerdict;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Support\RoleResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeHarnessForChain implements Harness {
    public array $requests = [];
    public HarnessResult $presetResult;
    public function __construct(HarnessResult $result) { $this->presetResult = $result; }
    public function runTask(HarnessRequest $request): HarnessResult {
        $this->requests[] = $request;
        return $this->presetResult;
    }
}

class FakeCoordinatorForChain extends ResourceCoordinator {
    public function __construct() {}
    public function ensure(string $id): \App\Runtime\Metallama\ModelState {
        return new \App\Runtime\Metallama\ModelState(id: $id, status: \App\Runtime\Metallama\ServerStatus::Online);
    }
}

class FakeReviewerService extends ReviewerService {
    public ReviewVerdict $presetVerdict;
    public function __construct(ReviewVerdict $verdict) {
        $this->presetVerdict = $verdict;
    }
    public function review(\App\Models\Task $task, string $diff, ?bool $testsPassed, ?\App\Support\RoleBinding $binding = null): ReviewVerdict {
        return $this->presetVerdict;
    }
}

function setupChainEnv(): string {
    $root = sys_get_temp_dir().'/majordom-chain-'.uniqid();
    Config::set('majordom.memory_root', $root);
    Config::set('majordom.metallama.base_url', 'http://localhost:11434');
    Config::set('majordom.builder.gateway_model', 'codellama');
    Config::set('majordom.builder.model', 'builder-model-id');
    Config::set('queue.connections.harness.driver', 'sync');
    return $root;
}

function createChainExecution(array $chain, array $taskAttrs = []): array {
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'status' => \App\Enums\ExecutionStatus::Running]);
    $task = Task::factory()->create(array_merge([
        'project_id' => $project->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'branch' => 'feat/1', 'status' => TaskStatus::Pending,
        'worktree_path' => '/tmp/wt',
    ], $taskAttrs));
    $execution->tasks()->save($task);
    
    $engine = app(WorkflowEngine::class);
    $engine->start($execution, $chain);
    
    return [$execution, $task, $project];
}

test('ChainStep normalize strings to defaults', function () {
    $steps = ChainStep::normalize(['build', 'review', 'delegate']);
    expect($steps[0]->type)->toBe('build')->and($steps[0]->role)->toBe('builder');
    expect($steps[1]->type)->toBe('review')->and($steps[1]->role)->toBe('reviewer');
    expect($steps[2]->type)->toBe('delegate')->and($steps[2]->role)->toBe('system');
});

test('ChainStep normalize arrays preserves values', function () {
    $steps = ChainStep::normalize([
        ['type' => 'build', 'role' => 'fastbuilder', 'config' => ['x' => 1]],
    ]);
    expect($steps[0]->type)->toBe('build')
        ->and($steps[0]->role)->toBe('fastbuilder')
        ->and($steps[0]->config)->toBe(['x' => 1]);
});

test('ChainStep toStorable inverse', function () {
    $steps = ChainStep::normalize(['build']);
    $stored = ChainStep::toStorable($steps);
    expect($stored)->toBe([['type' => 'build', 'role' => 'builder', 'config' => []]]);
});

test('engine start persists role and config into node input', function () {
    setupChainEnv();
    [$execution, , ] = createChainExecution([
        ['type' => 'build', 'role' => 'builder', 'config' => ['rescue_role' => 'architect']],
    ]);
    
    $node = $execution->nodes()->first();
    expect($node->input['role'])->toBe('builder')
        ->and($node->input['config']['rescue_role'])->toBe('architect');
});

test('BuildNode uses step role', function () {
    setupChainEnv();
    Role::create(['name' => 'fastbuilder', 'provider' => 'metallama', 'model' => 'fast-model', 'meta' => ['managed_model' => 'fast-managed']]);
    
    [$execution, $task, $project] = createChainExecution([
        ['type' => 'build', 'role' => 'fastbuilder', 'config' => []],
    ]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    $fakeHarness = new FakeHarnessForChain(new HarnessResult(
        status: HarnessStatus::Completed, diff: '', filesChanged: [], testsPassed: true, summary: 'Ok', openQuestions: [], rawLog: ''
    ));
    app()->instance(Harness::class, $fakeHarness);
    app()->instance(ResourceCoordinator::class, new FakeCoordinatorForChain());
    
    $node = $execution->nodes()->first();
    (new BuildNode($node->id))->handle();
    
    expect($fakeHarness->requests[0]->modelName)->toBe('fast-model');
});

test('BuildNode passes reviewer-flagged files as fileHints', function () {
    setupChainEnv();
    [$execution, $task, $project] = createChainExecution(['build', 'review', 'build']);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    $reviewNode = $execution->nodes()->where('type', 'review')->first();
    $reviewNode->update([
        'status' => NodeStatus::Completed,
        'output' => [
            'verdict' => [
                'comments' => [
                    ['file' => 'a.php', 'comment' => 'fix a'],
                    ['file' => 'b.php', 'comment' => 'fix b'],
                ]
            ]
        ]
    ]);
    
    $fakeHarness = new FakeHarnessForChain(new HarnessResult(
        status: HarnessStatus::Completed, diff: '', filesChanged: [], testsPassed: true, summary: 'Ok', openQuestions: [], rawLog: ''
    ));
    app()->instance(Harness::class, $fakeHarness);
    app()->instance(ResourceCoordinator::class, new FakeCoordinatorForChain());
    
    $buildNode2 = $execution->nodes()->where('type', 'build')->orderBy('id', 'desc')->first();
    (new BuildNode($buildNode2->id))->handle();
    
    expect($fakeHarness->requests[0]->fileHints)->toContain('a.php')->and($fakeHarness->requests[0]->fileHints)->toContain('b.php');
});

test('frontier rescue resets nodes and updates build role', function () {
    setupChainEnv();
    Config::set('majordom.workflow.max_revisions', 1);
    
    [$execution, $task, $project] = createChainExecution([
        ['type' => 'build', 'role' => 'builder', 'config' => []],
        ['type' => 'test', 'role' => 'system', 'config' => []],
        ['type' => 'review', 'role' => 'reviewer', 'config' => ['rescue_role' => 'architect']],
    ]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    $buildNode = $execution->nodes()->where('type', 'build')->first();
    $buildNode->update(['status' => NodeStatus::Completed, 'output' => ['diff' => 'diff --git']]);
    $testNode = $execution->nodes()->where('type', 'test')->first();
    $testNode->update(['status' => NodeStatus::Completed, 'output' => ['testsPassed' => true]]);
    
    $fakeReviewer = new FakeReviewerService(new ReviewVerdict(
        verdict: 'changes_requested',
        comments: [['file' => 'x.php', 'comment' => 'fix']],
        summary: 'Needs fix',
        questions: []
    ));
    app()->instance(ReviewerService::class, $fakeReviewer);
    
    $reviewNode = $execution->nodes()->where('type', 'review')->first();
    (new ReviewNode($reviewNode->id))->handle();
    
    $execution->refresh();
    expect($execution->status)->toBe(\App\Enums\ExecutionStatus::Running);
    
    $buildNode->refresh();
    expect($buildNode->input['role'])->toBe('architect')
        ->and($buildNode->input['config']['rescued'])->toBeTrue();
        
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Pending);
});

test('second exhaustion parks despite rescue_role', function () {
    setupChainEnv();
    Config::set('majordom.workflow.max_revisions', 1);
    
    [$execution, $task, $project] = createChainExecution([
        ['type' => 'build', 'role' => 'builder', 'config' => ['rescued' => true]],
        ['type' => 'test', 'role' => 'system', 'config' => []],
        ['type' => 'review', 'role' => 'reviewer', 'config' => ['rescue_role' => 'architect']],
    ]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    $buildNode = $execution->nodes()->where('type', 'build')->first();
    $buildNode->update(['status' => NodeStatus::Completed, 'output' => ['diff' => 'diff --git']]);
    $testNode = $execution->nodes()->where('type', 'test')->first();
    $testNode->update(['status' => NodeStatus::Completed, 'output' => ['testsPassed' => true]]);
    
    $fakeReviewer = new FakeReviewerService(new ReviewVerdict(
        verdict: 'changes_requested',
        comments: [['file' => 'x.php', 'comment' => 'fix']],
        summary: 'Needs fix',
        questions: []
    ));
    app()->instance(ReviewerService::class, $fakeReviewer);
    
    $reviewNode = $execution->nodes()->where('type', 'review')->first();
    (new ReviewNode($reviewNode->id))->handle();
    
    $execution->refresh();
    expect($execution->status)->toBe(\App\Enums\ExecutionStatus::Parked);
});

test('legacy string-chain workflow starts execution', function () {
    setupChainEnv();
    [$execution, , ] = createChainExecution(['build', 'test', 'review']);
    
    $nodes = $execution->nodes()->orderBy('id')->get();
    expect($nodes->pluck('type')->toArray())->toBe(['build', 'test', 'review']);
    expect($nodes->first()->input['role'])->toBe('builder');
});
