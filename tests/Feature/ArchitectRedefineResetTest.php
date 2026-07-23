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

/**
 * Seed a captured revision (M16-B): a prior planWritten note plus an Architect
 * message carrying the plan in `proposed_plan` — the state approvePlan() reads
 * when the owner approves a re-proposed plan.
 */
function seedRevision(Project $project, array $plan): void
{
    $project->consensusMessages()->create([
        'role' => \App\Enums\MessageRole::System, 'content' => 'plan written',
        'meta' => ['planWritten' => true],
    ]);
    $project->consensusMessages()->create([
        'role' => \App\Enums\MessageRole::Architect, 'content' => 'revised',
        'meta' => ['consensusClaimed' => true, 'proposed_plan' => $plan],
    ]);
}

it('re-arms Start build with the revised first pending task and a FRESH brief', function () {
    // BUG 2 (M12Bis), now via the M16-B consensus-revision path: approving a
    // re-proposed plan resets the loop, sets firstTaskId so "Start build"
    // appears, and regenerates the stale (poisoned) brief from the revision.
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

    // approvePlan makes no propose call (the plan is captured) — only the
    // brief-regeneration decompose call reaches the provider.
    app()->instance(Provider::class, new RedefineScriptedProvider([
        '# FRESH brief from the revised roadmap', // decompose reply (markdown, jsonMode false)
    ]));
    $service = new ArchitectService(app(ProviderRegistry::class), $memory, app(RepoIndex::class));

    seedRevision($project, ['roadmap_md' => "## M1 — Skeleton\nDo it.\n- [ ] T-001 — Do the thing (revised)\n", 'summary' => 'revised']);
    $service->approvePlan($project);

    $lastSystem = $project->consensusMessages()
        ->where('role', \App\Enums\MessageRole::System)->orderByDesc('id')->first();

    expect($lastSystem->meta['planWritten'] ?? false)->toBeTrue()
        ->and($lastSystem->meta['firstTaskId'] ?? null)->toBe('T-001')       // Start build re-armed
        ->and($task->fresh()->status)->toBe(TaskStatus::Pending)             // re-armed to Pending
        ->and(trim((string) $memory->read($project, 'tasks/T-001/task.md'))) // brief regenerated
            ->toBe('# FRESH brief from the revised roadmap');
});

it('approving a revision resets the loop after a valid revision', function () {
    config(['majordom.memory_root' => sys_get_temp_dir().'/mj-redef-'.uniqid()]);
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Parked]);

    app()->instance(Provider::class, new RedefineScriptedProvider([
        '# brief', // brief-regeneration decompose reply
    ]));
    $service = new ArchitectService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class));

    seedRevision($project, ['roadmap_md' => "## M1\n- [ ] T-001 — Do the thing\n", 'summary' => 'revised']);
    $service->approvePlan($project);

    expect($project->events()->where('name', 'plan.redefine_reset')->count())->toBe(1)
        ->and($exec->fresh()->status)->toBe(ExecutionStatus::Completed);
});

it('a redefine that supersedes a milestone removes its orphaned worktree and branch (M16-C)', function () {
    config([
        'majordom.memory_root' => sys_get_temp_dir().'/mj-redef-wt-'.uniqid(),
        'majordom.worktrees_root' => $wtRoot = sys_get_temp_dir().'/mj-wt-root-'.uniqid(),
    ]);
    // The WorktreeManager singleton was built fromConfig at boot — re-resolve it
    // so it picks up this test's worktrees_root.
    app()->forgetInstance(\App\Projects\Repositories\WorktreeManager::class);

    $repoDir = sys_get_temp_dir().'/mj-redef-repo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'proj', 'repo_path' => $repoDir]);
    \App\Models\Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    \App\Models\Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'position' => 2]);

    // M2's worktree exists on disk — the redefine must clean it.
    $droppedPath = $wtRoot.'/proj/M2';
    mkdir($droppedPath, 0755, true);

    // Bare fake → every git command is faked, successful, and recorded (a keyed
    // fake would let unlisted commands run for real against the empty .git dir).
    \Illuminate\Support\Facades\Process::fake();

    app()->instance(Provider::class, new RedefineScriptedProvider(['# brief']));
    $service = new ArchitectService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class));

    // The revised roadmap keeps only M1 — M2 is superseded.
    seedRevision($project, ['roadmap_md' => "## M1 — Skeleton\n- [ ] T-001 — Do the thing\n", 'summary' => 'dropped M2']);
    $service->approvePlan($project);

    $reconciled = $project->events()->where('name', 'worktrees.reconciled')->first();
    expect($reconciled)->not->toBeNull()
        ->and($reconciled->payload['removed'])->toBe(['M2']);

    \Illuminate\Support\Facades\Process::assertRan(fn ($run) => $run->command === ['git', 'worktree', 'remove', '--force', $droppedPath]);
    \Illuminate\Support\Facades\Process::assertRan(fn ($run) => $run->command === ['git', 'branch', '-D', 'majordom/M2']);
    // The surviving milestone is never touched.
    \Illuminate\Support\Facades\Process::assertDidntRun(fn ($run) => $run->command === ['git', 'branch', '-D', 'majordom/M1']);
});

it('a redefine that omits a BUILT milestone spares its worktree and branch (M16-D2 freeze)', function () {
    config([
        'majordom.memory_root' => sys_get_temp_dir().'/mj-redef-built-'.uniqid(),
        'majordom.worktrees_root' => $wtRoot = sys_get_temp_dir().'/mj-wt-built-'.uniqid(),
    ]);
    app()->forgetInstance(\App\Projects\Repositories\WorktreeManager::class);

    $repoDir = sys_get_temp_dir().'/mj-redef-built-repo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'proj', 'repo_path' => $repoDir]);
    $m1 = \App\Models\Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    $m2 = \App\Models\Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'position' => 2]);
    // M2 is BUILT — an approved task makes deriveStatus() 'done', so a revision
    // that drops its key must NOT destroy the unmerged work.
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m1->id, 'task_key' => 'T-001', 'status' => TaskStatus::Approved, 'declared_status' => 'done', 'position' => 1]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m2->id, 'task_key' => 'T-002', 'status' => TaskStatus::Approved, 'declared_status' => 'done', 'position' => 1]);

    mkdir($wtRoot.'/proj/M2', 0755, true);
    \Illuminate\Support\Facades\Process::fake();

    app()->instance(Provider::class, new RedefineScriptedProvider(['# brief']));
    $service = new ArchitectService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class));

    // The revision keeps only M1 — but M2 is built, so it must be spared.
    seedRevision($project, ['roadmap_md' => "## M1 — Skeleton\n- [x] T-001 — Do the thing\n", 'summary' => 'dropped built M2']);
    $service->approvePlan($project);

    // M2's branch is never deleted, and M2 survives in the DB (frozen).
    \Illuminate\Support\Facades\Process::assertDidntRun(fn ($run) => $run->command === ['git', 'branch', '-D', 'majordom/M2']);
    \Illuminate\Support\Facades\Process::assertDidntRun(fn ($run) => $run->command === ['git', 'worktree', 'remove', '--force', $wtRoot.'/proj/M2']);
    expect(\App\Models\Milestone::where('project_id', $project->id)->where('milestone_key', 'M2')->exists())->toBeTrue();
    // No milestone was reconciled away.
    expect($project->events()->where('name', 'worktrees.reconciled')->exists())->toBeFalse();
});
