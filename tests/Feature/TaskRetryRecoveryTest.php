<?php

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Enums\ImplementationStrategy;
use App\Enums\TaskStatus;
use App\Jobs\RunTaskRetry;
use App\Livewire\ProjectWorkspace;
use App\Models\Event;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => setupMemoryRoot());

/** Fake architect Provider returning a fixed markdown brief for decompose. */
function bindRetryArchitect(string $brief): void
{
    $fake = new class($brief) implements Provider {
        public function __construct(public string $brief) {}

        public function chat(ProviderRequest $request): ProviderResponse
        {
            return new ProviderResponse($this->brief, 'stop', 1, 1);
        }
    };
    app()->instance(Provider::class, $fake);
}

it('retries a failed task with a FRESH brief and relaunches the build', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    $task = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id,
        'task_key' => 'T-014', 'status' => TaskStatus::Failed, 'revision' => 5, 'position' => 14,
    ]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — x\n- [ ] T-014 — do it\n");
    app(MemoryStore::class)->write($project, 'tasks/T-014/task.md', '# OLD poisoned brief with gradlew');

    bindRetryArchitect('# T-014 fresh brief, grounded');

    (new RunTaskRetry($project->id, 'T-014', false, 'attended'))->handle(app(\App\Agents\Architect\ArchitectService::class));

    expect(trim((string) app(MemoryStore::class)->read($project, 'tasks/T-014/task.md')))->toBe('# T-014 fresh brief, grounded')
        ->and(Event::where('project_id', $project->id)->where('name', 'task.retried')->count())->toBe(1)
        ->and($project->executions()->count())->toBe(1) // relaunched
        ->and($task->fresh()->implementation_strategy)->toBeNull(); // not escalated
});

it('escalates the retry to the frontier Builder when asked', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id,
        'task_key' => 'T-014', 'status' => TaskStatus::Failed, 'position' => 14,
    ]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — x\n- [ ] T-014 — do it\n");

    bindRetryArchitect('# fresh brief');

    (new RunTaskRetry($project->id, 'T-014', true, 'attended'))->handle(app(\App\Agents\Architect\ArchitectService::class));

    expect($project->tasks()->where('task_key', 'T-014')->latest('id')->first()->strategy())
        ->toBe(ImplementationStrategy::Frontier)
        ->and(Event::where('project_id', $project->id)->where('name', 'task.builder_selected')->count())->toBe(1);
});

it('the workspace action dispatches the retry job', function () {
    Queue::fake();
    $project = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id, 'task_key' => 'T-014', 'status' => TaskStatus::Failed]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('retryTask', 'T-014', true);

    Queue::assertPushed(RunTaskRetry::class, fn ($job) => $job->taskKey === 'T-014' && $job->escalateToFrontier === true);
});
