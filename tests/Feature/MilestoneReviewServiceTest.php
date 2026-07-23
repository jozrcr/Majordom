<?php

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Reviewer\MilestoneReviewService;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class MsrScriptedProvider implements Provider
{
    public array $requests = [];

    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses);

        return $next instanceof ProviderResponse ? $next : new ProviderResponse($next ?? '', 'stop', 5, 5);
    }
}

beforeEach(function () {
    config(['majordom.memory_root' => sys_get_temp_dir().'/mj-msr-mem-'.uniqid()]);
    $this->wtRoot = sys_get_temp_dir().'/mj-msr-wt-'.uniqid();
    config(['majordom.worktrees_root' => $this->wtRoot]);

    $this->project = Project::factory()->create(['slug' => 'proj']);
    $this->milestone = Milestone::factory()->create([
        'project_id' => $this->project->id, 'milestone_key' => 'M1', 'title' => 'Skeleton', 'summary' => 'Stand up the shell',
    ]);
    $this->wtPath = $this->wtRoot.'/proj/M1';
    mkdir($this->wtPath, 0777, true);
    Task::factory()->create([
        'project_id' => $this->project->id, 'milestone_id' => $this->milestone->id,
        'task_key' => 'T-001', 'title' => 'First', 'position' => 1,
        'base_commit' => 'abc123', 'worktree_path' => $this->wtPath,
    ]);
    app(MemoryStore::class)->write($this->project, 'tasks/T-001/task.md', "# First\n## Acceptance criteria\n- does X");
});

afterEach(function () {
    foreach ([$this->wtRoot, config('majordom.memory_root')] as $d) {
        if (is_string($d) && is_dir($d)) {
            exec('rm -rf '.escapeshellarg($d));
        }
    }
});

function reviewService(array $responses): MilestoneReviewService
{
    app()->instance(Provider::class, new MsrScriptedProvider($responses));

    return new MilestoneReviewService(
        app(ProviderRegistry::class),
        MemoryStore::fromConfig(),
        app(RepoIndex::class),
        app(WorktreeManager::class),
    );
}

it('reads the cumulative diff then approves the milestone', function () {
    Process::fake(['*' => Process::result("diff --git a/x b/x\n+built X")]);

    $outcome = reviewService([archReviewReadDiff(), archReviewApprove('meets the goal')])
        ->review($this->milestone);

    expect($outcome->isApproved())->toBeTrue()
        ->and($outcome->summary)->toBe('meets the goal');
});

it('requests changes with concrete keyed items', function () {
    Process::fake(['*' => Process::result("diff --git a/x b/x\n+wip")]);

    $outcome = reviewService([
        archReviewReadDiff(),
        archReviewChanges([
            ['task_key' => 'T-001', 'file' => 'x.php', 'reason' => 'X is not actually wired up'],
            ['reason' => 'missing the Y case'],
        ], 'two gaps'),
    ])->review($this->milestone);

    expect($outcome->isChanges())->toBeTrue()
        ->and($outcome->items)->toHaveCount(2)
        ->and($outcome->items[0]['reason'])->toBe('X is not actually wired up')
        ->and($outcome->items[0]['file'])->toBe('x.php');
});

it('escalates to the owner via ask_owner', function () {
    Process::fake(['*' => Process::result("diff --git a/x b/x\n+wip")]);

    $outcome = reviewService([archReviewReadDiff(), archReviewEscalate(['Which storage engine?'], 'ambiguous spec')])
        ->review($this->milestone);

    expect($outcome->isEscalate())->toBeTrue()
        ->and($outcome->questions)->toBe(['Which storage engine?']);
});

it('short-circuits to approved with no model call when there is no diff', function () {
    // No worktree dir → no diff → nothing to review.
    exec('rm -rf '.escapeshellarg($this->wtPath));
    Task::query()->update(['worktree_path' => null]);

    $provider = new MsrScriptedProvider([]);
    app()->instance(Provider::class, $provider);
    $service = new MilestoneReviewService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class), app(WorktreeManager::class));

    $outcome = $service->review($this->milestone);

    expect($outcome->isApproved())->toBeTrue()
        ->and($provider->requests)->toBeEmpty();
});

it('grounds the review context in the milestone goal and task briefs', function () {
    Process::fake(['*' => Process::result("diff --git a/x b/x\n+built X")]);

    $provider = new MsrScriptedProvider([archReviewReadDiff(), archReviewApprove()]);
    app()->instance(Provider::class, $provider);
    $service = new MilestoneReviewService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class), app(WorktreeManager::class));

    $service->review($this->milestone);

    $userMsg = collect($provider->requests[0]->messages)->firstWhere('role', 'user')['content'];
    expect($userMsg)->toContain('Stand up the shell') // milestone goal
        ->and($userMsg)->toContain('T-001')           // task in the milestone
        ->and($userMsg)->toContain('does X');          // its acceptance criteria
});
