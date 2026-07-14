<?php

use App\Core\Workflow\TaskChain;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake(); // startForTask dispatches the first node — don't run it
    setupMemoryRoot();
});

/** A milestone with 2 tasks; task 1 just committed. Returns [project, milestone, t1, t2]. */
function chainFixture(string $profile = 'attended'): array
{
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'profile' => $profile]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1']);
    $t1 = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'title' => 'First', 'position' => 1, 'status' => TaskStatus::Approved,
    ]);
    $t2 = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'execution_id' => null,
        'task_key' => 'T-002', 'title' => 'Second', 'position' => 2, 'status' => TaskStatus::Pending,
    ]);
    // roadmap/architecture so decompose has context; give T-002 a brief so decompose is a no-op (no network).
    $mem = app(MemoryStore::class);
    $mem->write($project, 'roadmap.md', "## M1 — Skeleton\n- [x] T-001 — First\n- [ ] T-002 — Second\n");
    $mem->write($project, 'architecture.md', 'App.');
    $mem->write($project, 'tasks/T-002/task.md', '# Second brief'); // decompose short-circuits

    return [$project, $milestone, $t1, $t2];
}

test('advance starts the next pending task in the milestone', function () {
    [$project, , $t1, $t2] = chainFixture();

    app(TaskChain::class)->advance($t1);

    // T-002 got an execution (startForTask ran) + autoadvanced event
    expect($t2->fresh()->execution_id)->not->toBeNull();
    expect(Event::where('name', 'task.autoadvanced')->where('project_id', $project->id)->exists())->toBeTrue();
    expect(Event::where('name', 'milestone.tasks_complete')->exists())->toBeFalse();
});

test('advance emits milestone.tasks_complete when no pending task remains', function () {
    [$project, $milestone, $t1, $t2] = chainFixture();
    $t2->update(['status' => TaskStatus::Approved]); // both done

    app(TaskChain::class)->advance($t1);

    expect(Event::where('name', 'milestone.tasks_complete')->where('project_id', $project->id)->exists())->toBeTrue();
    expect(Event::where('name', 'task.autoadvanced')->exists())->toBeFalse();
});

test('advance is a no-op for a task with no milestone', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id, 'milestone_id' => null]);

    app(TaskChain::class)->advance($task);

    expect(Event::where('project_id', $project->id)->exists())->toBeFalse();
});

test('advance carries the execution profile to the next task', function () {
    [$project, , $t1, $t2] = chainFixture('overnight');

    app(TaskChain::class)->advance($t1);

    expect($t2->fresh()->execution->profile)->toBe('overnight');
});
