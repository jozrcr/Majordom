<?php

use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessResult;
use App\Agents\Harness\HarnessStatus;
use App\Core\Workflow\Nodes\BuildNode;
use App\Core\Workflow\Nodes\DelegateNode;
use App\Core\Workflow\Nodes\TestNode;
use App\Core\Workflow\NodeResult;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Runtime\Metallama\ModelState;
use App\Runtime\Metallama\ServerStatus;
use App\Runtime\Metallama\ResourceCoordinator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeHarness implements Harness
{
    public array $requests = [];
    public HarnessResult $presetResult;

    public function __construct(HarnessResult $result)
    {
        $this->presetResult = $result;
    }

    public function runTask(HarnessRequest $request): HarnessResult
    {
        $this->requests[] = $request;
        return $this->presetResult;
    }
}

class FakeCoordinator extends ResourceCoordinator
{
    public array $ensured = [];
    public function __construct() { }
    public function ensure(string $id): ModelState
    {
        $this->ensured[] = $id;
        return new ModelState(id: $id, status: ServerStatus::Online);
    }
}

// Shared helpers setupMemoryRoot() / createExecutionWithTask() live in tests/Pest.php

test('DelegateNode writes role.md, creates worktree, and sets task to Building', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    [$execution, $task, $node, $project] = createExecutionWithTask([], ['repo_path' => $repoDir]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Build something.");
    
    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
        "'git' 'worktree' 'add'*" => Process::result(output: 'ok'),
    ]);

    $job = new DelegateNode($node->id);
    $job->handle();

    $node->refresh();
    expect($node->status)->toBe(\App\Enums\NodeStatus::Completed);
    expect($node->output['worktree'])->not->toBeNull();
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Building);
    expect($memory->read($project, "tasks/{$task->task_key}/role.md"))->toContain('You are the Builder');
});

test('DelegateNode uses the shared milestone worktree for a milestone task', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    [$execution, $task, $node, $project] = createExecutionWithTask([], ['repo_path' => $repoDir]);

    $milestone = \App\Models\Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1']);
    $task->update(['milestone_id' => $milestone->id]);

    app(MemoryStore::class)->write($project, "tasks/{$task->task_key}/task.md", "Build something.");

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
        "'git' 'worktree' 'add' '-b' 'majordom/M1'*" => Process::result(output: 'ok'),
    ]);

    (new DelegateNode($node->id))->handle();

    $task->refresh();
    expect($task->branch)->toBe('majordom/M1');
    expect($task->worktree_path)->toEndWith('/M1');
    // The branch created is the milestone's, not a per-task one.
    Process::assertRan(fn ($p) => is_array($p->command)
        && in_array('-b', $p->command, true) && in_array('majordom/M1', $p->command, true));
});

test('DelegateNode fails when task.md is missing', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask();
    
    Process::fake();

    $job = new DelegateNode($node->id);
    $job->handle();

    expect($node->refresh()->status)->toBe(\App\Enums\NodeStatus::Failed);
    $execution->refresh();
    expect($execution->meta['parked_reason'] ?? '')->toContain('Task brief');
});

test('BuildNode coordinates, runs harness, writes handoff, and sets task to Testing', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/worktree',
    ]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role prompt");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task prompt");
    
    Config::set('majordom.metallama.base_url', 'http://localhost:11434');
    Config::set('majordom.builder.gateway_model', 'codellama');
    Config::set('majordom.builder.model', 'builder-model-id');
    Config::set('queue.connections.harness.driver', 'sync');

    $fakeHarness = new FakeHarness(new HarnessResult(
        status: HarnessStatus::Completed,
        diff: 'diff --git',
        filesChanged: ['a.php'],
        testsPassed: true,
        summary: 'Done',
        openQuestions: [],
        rawLog: 'log line 1'
    ));
    app()->instance(Harness::class, $fakeHarness);
    app()->instance(ResourceCoordinator::class, new FakeCoordinator());

    $job = new BuildNode($node->id);
    $job->handle();

    expect($node->refresh()->output['diff'])->toBe('diff --git');
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Testing);
    expect($memory->read($project, "tasks/{$task->task_key}/handoff.md"))->toContain('Done');
    expect($fakeHarness->requests[0]->rolePrompt)->toBe('Role prompt');
    expect($fakeHarness->requests[0]->taskPrompt)->toBe('Task prompt');
    expect($fakeHarness->requests[0]->repoPath)->toBe('/tmp/worktree');
});

test('BuildNode failure parks execution and sets task to Failed', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/worktree',
    ]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    Config::set('majordom.metallama.base_url', 'http://localhost:11434');
    Config::set('majordom.builder.gateway_model', 'codellama');
    Config::set('majordom.builder.model', 'builder-model-id');

    $fakeHarness = new FakeHarness(new HarnessResult(
        status: HarnessStatus::Failed,
        diff: '',
        filesChanged: [],
        testsPassed: false,
        summary: 'Crashed',
        openQuestions: [],
        rawLog: 'err'
    ));
    app()->instance(Harness::class, $fakeHarness);
    app()->instance(ResourceCoordinator::class, new FakeCoordinator());

    $job = new BuildNode($node->id);
    $job->handle();

    expect($node->refresh()->status)->toBe(\App\Enums\NodeStatus::Failed);
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    $execution->refresh();
    expect($execution->meta['parked_reason'] ?? '')->toContain('Build failed');
});

test('BuildNode uses task.v2.md when revision is 2', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/worktree',
        'revision' => 2,
    ]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Original");
    $memory->write($project, "tasks/{$task->task_key}/task.v2.md", "Revised v2 marker");
    
    Config::set('majordom.metallama.base_url', 'http://localhost:11434');
    Config::set('majordom.builder.gateway_model', 'codellama');
    Config::set('majordom.builder.model', 'builder-model-id');

    $fakeHarness = new FakeHarness(new HarnessResult(
        status: HarnessStatus::Completed,
        diff: '',
        filesChanged: [],
        testsPassed: true,
        summary: 'Ok',
        openQuestions: [],
        rawLog: ''
    ));
    app()->instance(Harness::class, $fakeHarness);
    app()->instance(ResourceCoordinator::class, new FakeCoordinator());

    $job = new BuildNode($node->id);
    $job->handle();

    expect($fakeHarness->requests[0]->taskPrompt)->toContain('Revised v2 marker');
});

test('TestNode skips when no test_command', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/worktree',
    ]);
    $project->update(['test_command' => null]);

    $job = new TestNode($node->id);
    $job->handle();

    expect($node->refresh()->output['skipped'])->toBeTrue();
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Reviewing);
});

test('TestNode passes and sets task to Reviewing', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/worktree',
    ]);
    $project->update(['test_command' => 'php artisan test']);

    Process::fake([
        'php artisan test' => Process::result(output: 'OK', exitCode: 0),
    ]);

    $job = new TestNode($node->id);
    $job->handle();

    expect($node->refresh()->output['testsPassed'])->toBeTrue();
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Reviewing);
});

test('TestNode failure writes revision brief and increments revision', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/worktree',
        'revision' => 1,
    ]);
    $project->update(['test_command' => 'php artisan test']);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Original brief");

    Process::fake([
        'php artisan test' => Process::result(output: 'FAIL', exitCode: 1),
    ]);

    Config::set('queue.connections.harness.driver', 'database');
    $node->update(['type' => 'test']); // real map type so advance() can dispatch
    $job = new TestNode($node->id);
    $job->handle();

    // Bounded revise loop: within budget the node goes back to pending.
    expect($node->refresh()->status)->toBe(\App\Enums\NodeStatus::Pending);
    $task->refresh();
    expect($task->revision)->toBe(2);
    expect($task->status)->toBe(TaskStatus::Pending);
    $brief = $memory->read($project, "tasks/{$task->task_key}/task.v2.md");
    expect($brief)->toContain('Original brief');
    expect($brief)->toContain('## Test failure (revision 2)');
    expect($brief)->toContain('FAIL');
    $execution->refresh();
    expect($execution->status)->toBe(\App\Enums\ExecutionStatus::Running);
});

test('TestNode failure beyond the revision budget parks', function () {
    setupMemoryRoot();
    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => '/tmp/worktree',
        'revision' => 3,
    ]);
    $project->update(['test_command' => 'php artisan test']);
    app(MemoryStore::class)->write($project, "tasks/{$task->task_key}/task.md", "Brief");

    Process::fake(['php artisan test' => Process::result(output: 'FAIL', exitCode: 1)]);

    (new TestNode($node->id))->handle();

    expect($execution->refresh()->status)->toBe(\App\Enums\ExecutionStatus::Parked)
        ->and($execution->meta['parked_reason'])->toContain('still failing');
});
