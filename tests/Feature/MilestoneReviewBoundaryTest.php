<?php

use App\Agents\Reviewer\MilestoneReviewOutcome;
use App\Agents\Reviewer\MilestoneReviewService;
use App\Core\Workflow\TaskChain;
use App\Enums\ApprovalType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** A milestone review that returns a canned outcome, no model/git involved. */
class FakeMilestoneReview extends MilestoneReviewService
{
    public function __construct(public MilestoneReviewOutcome $outcome) {}

    public function review(Milestone $milestone): MilestoneReviewOutcome
    {
        return $this->outcome;
    }
}

beforeEach(function () {
    Queue::fake();
    setupMemoryRoot();
});

/** A milestone whose only task is done; advancing it reaches the boundary. */
function boundaryFixture(string $profile = 'attended'): array
{
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'profile' => $profile]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'title' => 'Skeleton']);
    $task = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'title' => 'First', 'position' => 1, 'status' => TaskStatus::Approved,
    ]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — Skeleton\n- [x] T-001 — First\n");

    return [$project, $milestone, $task];
}

function bindReview(MilestoneReviewOutcome $outcome): void
{
    app()->instance(MilestoneReviewService::class, new FakeMilestoneReview($outcome));
}

it('approved review raises the human merge gate', function () {
    [$project, , $task] = boundaryFixture();
    bindReview(new MilestoneReviewOutcome('approved', 'meets the goal'));

    app(TaskChain::class)->advance($task);

    expect($project->approvals()->where('type', ApprovalType::MilestoneMerge)->count())->toBe(1)
        ->and(Event::where('project_id', $project->id)->where('name', 'milestone.review_approved')->exists())->toBeTrue();
});

it('requested changes create ONE keyed fix-task and rebuild it, no merge gate yet', function () {
    [$project, $milestone, $task] = boundaryFixture();
    bindReview(new MilestoneReviewOutcome('changes', 'a gap', [
        ['task_key' => 'T-001', 'file' => 'x.php', 'reason' => 'X is not wired up'],
    ]));

    app(TaskChain::class)->advance($task);

    $fix = $project->tasks()->where('task_key', 'T-002')->first();
    expect($fix)->not->toBeNull()
        ->and($fix->title)->toContain('Address review findings')
        ->and($fix->status)->toBe(TaskStatus::Pending)
        ->and($fix->milestone_id)->toBe($milestone->id)
        ->and(app(MemoryStore::class)->read($project, 'tasks/T-002/task.md'))->toContain('X is not wired up')
        ->and($project->approvals()->where('type', ApprovalType::MilestoneMerge)->count())->toBe(0)
        ->and(Event::where('project_id', $project->id)->where('name', 'milestone.changes_requested')->exists())->toBeTrue();
});

it('escalates to an actionable merge gate when the reviewer asks the owner', function () {
    [$project, , $task] = boundaryFixture();
    bindReview(new MilestoneReviewOutcome('escalate', 'unclear spec', [], ['Which storage engine?']));

    app(TaskChain::class)->advance($task);

    $approval = $project->approvals()->where('type', ApprovalType::MilestoneMerge)->first();
    expect($approval)->not->toBeNull()
        ->and($approval->title)->toContain('Which storage engine?')
        ->and(Event::where('project_id', $project->id)->where('name', 'milestone.review_escalated')->exists())->toBeTrue();
});

it('convergence guard: after two change rounds, a third escalates instead of looping', function () {
    [$project, $milestone, $task] = boundaryFixture();

    // Two prior change rounds already happened for this milestone.
    foreach ([1, 2] as $round) {
        Event::create([
            'project_id' => $project->id, 'name' => 'milestone.changes_requested',
            'payload' => ['milestone_key' => 'M1', 'round' => $round], 'actor' => 'reviewer',
        ]);
    }

    bindReview(new MilestoneReviewOutcome('changes', 'still a gap', [['reason' => 'again']]));

    app(TaskChain::class)->advance($task);

    // No new fix-task; instead an actionable gate + a "stuck" signal.
    expect($project->tasks()->where('title', 'like', 'Address review findings%')->count())->toBe(0)
        ->and($project->approvals()->where('type', ApprovalType::MilestoneMerge)->count())->toBe(1)
        ->and(Event::where('project_id', $project->id)->where('name', 'milestone.review_stuck')->exists())->toBeTrue();
});
