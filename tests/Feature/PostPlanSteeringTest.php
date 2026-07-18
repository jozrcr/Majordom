<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Enums\MessageRole;
use App\Enums\TaskStatus;
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

/** Bind a fake architect Provider returning $content; capture the request. */
function bindArchitect(string $content): object
{
    $fake = new class($content) implements Provider {
        public ?ProviderRequest $lastRequest = null;
        public function __construct(public string $content) {}
        public function chat(ProviderRequest $request): ProviderResponse
        {
            $this->lastRequest = $request;
            return new ProviderResponse(content: $this->content, finishReason: 'stop', promptTokens: 1, completionTokens: 1);
        }
    };
    app()->instance(Provider::class, $fake);
    return $fake;
}

function planned(Project $project): void
{
    $project->consensusMessages()->create([
        'role' => MessageRole::System, 'content' => 'plan written',
        'meta' => ['planWritten' => true],
    ]);
}

test('addContext writes decisions.md, notes it in chat, emits an event', function () {
    $project = Project::factory()->create();

    app(ArchitectService::class)->addContext($project, 'Must also work on Wayland.');

    $decisions = app(MemoryStore::class)->read($project, 'decisions.md');
    expect($decisions)->toContain('Must also work on Wayland.');
    expect($project->consensusMessages()->where('role', MessageRole::System)->get()
        ->contains(fn ($m) => str_contains($m->content, 'Context added')))->toBeTrue();
    expect(Event::where('name', 'context.added')->where('project_id', $project->id)->exists())->toBeTrue();
});

test('added context reaches the Builder — it appears in the decompose brief context', function () {
    $project = Project::factory()->create();
    $m = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1']);
    $task = Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m->id, 'task_key' => 'T-002', 'position' => 2, 'execution_id' => null]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — x\n- [ ] T-002 — y\n");
    app(ArchitectService::class)->addContext($project, 'CONSTRAINT-XYZ must hold.');

    $fake = bindArchitect("# brief\n## Goal\nx");
    app(ArchitectService::class)->decomposeTask($project, $task);

    $userMsg = collect($fake->lastRequest->messages)->firstWhere('role', 'user')['content'];
    expect($userMsg)->toContain('CONSTRAINT-XYZ must hold.');
});

test('redefinePlan revises roadmap.md and re-syncs', function () {
    $project = Project::factory()->create();
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — Old\n- [ ] T-001 — a\n");
    bindArchitect(json_encode([
        'roadmap_md' => "## M1 — Old\n- [ ] T-001 — a\n- [ ] T-002 — new task\n",
        'summary' => 'Added T-002.',
    ]));

    app(ArchitectService::class)->redefinePlan($project, 'Add a task for the new task.');

    expect(app(MemoryStore::class)->read($project, 'roadmap.md'))->toContain('T-002 — new task');
    expect(Task::where('project_id', $project->id)->where('task_key', 'T-002')->exists())->toBeTrue(); // re-synced
    expect(Event::where('name', 'plan.redefined')->exists())->toBeTrue();
});

test('after a redefine the workspace shows Start build for the revised first task (live path)', function () {
    // BUG 2 live-path guard: exercise the real redefinePlan, then mount the
    // actual component — the button must appear (the mechanism test alone
    // missed that firstTaskId never reached the component before).
    $project = Project::factory()->create();
    $m = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m->id, 'task_key' => 'T-001', 'status' => TaskStatus::Failed, 'position' => 1, 'execution_id' => null]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — Old\n- [ ] T-001 — a\n");
    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', '# OLD brief');

    bindArchitect(json_encode(['roadmap_md' => "## M1 — Old\n- [ ] T-001 — a (revised)\n", 'summary' => 'revised']));
    app(ArchitectService::class)->redefinePlan($project, 'reshape it');

    Livewire::test(ProjectWorkspace::class, ['project' => $project->fresh()])
        ->assertSee('Start build')
        ->assertSee('T-001');
});

test('post-plan composer shows steering buttons, not free chat', function () {
    $project = Project::factory()->create();
    planned($project);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Add context')
        ->assertSee('Redefine milestones')
        ->assertDontSee('Describe what to build');
});

test('submitChatMode routes add_context synchronously and redefine to a job', function () {
    Queue::fake();
    $project = Project::factory()->create();
    planned($project);

    // add_context: runs now, writes decisions.md
    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('setChatMode', 'add_context')
        ->set('draft', 'note A')
        ->call('submitChatMode');
    expect(app(MemoryStore::class)->read($project, 'decisions.md'))->toContain('note A');

    // redefine: dispatches the job
    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('setChatMode', 'redefine')
        ->set('draft', 'reshape it')
        ->call('submitChatMode');
    Queue::assertPushed(\App\Jobs\RunPlanRedefine::class);
});
