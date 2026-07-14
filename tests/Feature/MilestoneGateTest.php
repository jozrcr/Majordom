<?php

use App\Core\Workflow\TaskChain;
use App\Core\Workflow\WorkflowEngine;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    setupMemoryRoot();
    config(['majordom.git.author_name' => 'Test User', 'majordom.git.author_email' => 'test@example.com']);
});

/** Two milestones (M1 done, M2 pending first task); returns [project, m1, lastTaskOfM1]. */
function gateFixture(string $profile): array
{
    $repo = sys_get_temp_dir().'/majordom-gate-'.uniqid();
    mkdir($repo.'/.git', 0755, true);
    $project = Project::factory()->create(['repo_path' => $repo]);
    $execution = Execution::factory()->create(['project_id' => $project->id, 'profile' => $profile]);

    $m1 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    $m2 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'position' => 2, 'title' => 'Next MS']);

    $last = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $m1->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'position' => 1, 'status' => TaskStatus::Approved,
    ]);
    Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $m2->id, 'execution_id' => null,
        'task_key' => 'T-002', 'title' => 'M2 first', 'position' => 1, 'status' => TaskStatus::Pending,
    ]);
    app(\App\Projects\Memory\MemoryStore::class)->write($project, 'tasks/T-002/task.md', '# brief');

    return [$project, $m1, $last];
}

test('attended: milestone boundary raises a MilestoneMerge gate (no auto-merge)', function () {
    [$project, $m1, $last] = gateFixture('attended');
    Process::fake(); // no git should run — assert none

    app(TaskChain::class)->advance($last);

    $gate = Approval::where('project_id', $project->id)->where('type', ApprovalType::MilestoneMerge)->first();
    expect($gate)->not->toBeNull();
    expect($gate->payload['milestone_id'])->toBe($m1->id);
    Process::assertNothingRan(); // did NOT merge automatically
});

test('full_auto: milestone boundary merges to main and starts the next milestone', function () {
    [$project, , $last] = gateFixture('full_auto');
    // milestone worktree present so removeMilestoneWorktree runs its git path
    $wt = (new WorktreeManager(sys_get_temp_dir().'/majordom-gwt-'.uniqid()));
    app()->instance(WorktreeManager::class, $wt);

    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: ''),
        "'git' 'rev-parse' '--verify' 'majordom/M1'" => Process::result(output: "abc\n"),
        "'git' 'merge' '--no-ff' 'majordom/M1'*" => Process::result(output: 'merged'),
    ]);

    app(TaskChain::class)->advance($last);

    expect(Event::where('name', 'milestone.merged')->where('project_id', $project->id)->exists())->toBeTrue();
    expect(Event::where('name', 'milestone.started')->where('project_id', $project->id)->exists())->toBeTrue();
    expect(Task::where('project_id', $project->id)->where('task_key', 'T-002')->first()->execution_id)->not->toBeNull();
    // no gate approval under full_auto
    expect(Approval::where('type', ApprovalType::MilestoneMerge)->exists())->toBeFalse();
});

test('granting the milestone gate merges and starts the next milestone', function () {
    [$project, $m1] = gateFixture('attended');
    $wt = (new WorktreeManager(sys_get_temp_dir().'/majordom-gwt-'.uniqid()));
    app()->instance(WorktreeManager::class, $wt);

    $gate = $project->approvals()->create([
        'type' => ApprovalType::MilestoneMerge,
        'title' => 'M1 complete',
        'payload' => ['milestone_id' => $m1->id, 'profile' => 'attended'],
        'status' => ApprovalStatus::Open,
    ]);

    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: ''),
        "'git' 'rev-parse' '--verify' 'majordom/M1'" => Process::result(output: "abc\n"),
        "'git' 'merge' '--no-ff' 'majordom/M1'*" => Process::result(output: 'merged'),
    ]);

    app(WorkflowEngine::class)->resolveApproval($gate, true);

    expect($gate->fresh()->status)->toBe(ApprovalStatus::Granted);
    expect(Event::where('name', 'milestone.merged')->exists())->toBeTrue();
    expect(Task::where('task_key', 'T-002')->first()->execution_id)->not->toBeNull();
});

test('declining the milestone gate merges nothing', function () {
    [$project, $m1] = gateFixture('attended');
    Process::fake();

    $gate = $project->approvals()->create([
        'type' => ApprovalType::MilestoneMerge, 'title' => 'M1', 'payload' => ['milestone_id' => $m1->id],
        'status' => ApprovalStatus::Open,
    ]);

    app(WorkflowEngine::class)->resolveApproval($gate, false);

    expect($gate->fresh()->status)->toBe(ApprovalStatus::Rejected);
    Process::assertNothingRan();
});
