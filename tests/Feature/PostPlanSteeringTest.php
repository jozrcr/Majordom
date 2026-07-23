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

/**
 * Seed the state approvePlan() reads for a REVISION (M16-B): a prior planWritten
 * note, plus an Architect message carrying the plan the owner is approving in its
 * `proposed_plan` meta — exactly what a post-plan propose_plan turn writes.
 */
function capturedRevision(Project $project, array $plan): void
{
    planned($project);
    $project->consensusMessages()->create([
        'role' => MessageRole::Architect,
        'content' => 'Here is the revised plan.',
        'meta' => ['consensusClaimed' => true, 'proposed_plan' => $plan],
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

test('approving a revision updates roadmap.md and re-syncs, preserving keys', function () {
    // M16-B: post-plan chat reaches a re-proposed plan; approving it revises the
    // roadmap through the same reconciliation the old one-shot redefine used —
    // now sourced from the captured propose_plan, not a second model call.
    $project = Project::factory()->create();
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — Old\n- [ ] T-001 — a\n");
    bindArchitect('# fresh brief'); // decompose reply while regenerating the restart brief
    capturedRevision($project, [
        'roadmap_md' => "## M1 — Old\n- [ ] T-001 — a\n- [ ] T-002 — new task\n",
        'summary' => 'Added T-002.',
    ]);

    app(ArchitectService::class)->approvePlan($project);

    expect(app(MemoryStore::class)->read($project, 'roadmap.md'))->toContain('T-002 — new task');
    expect(Task::where('project_id', $project->id)->where('task_key', 'T-002')->exists())->toBeTrue(); // re-synced
    expect(Event::where('name', 'plan.redefined')->exists())->toBeTrue();
});

test('after an approved revision the workspace shows Start build for the revised first task (live path)', function () {
    // Live-path guard: exercise the real approvePlan revision, then mount the
    // actual component — the button must appear (firstTaskId must reach the UI).
    $project = Project::factory()->create();
    $m = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m->id, 'task_key' => 'T-001', 'status' => TaskStatus::Failed, 'position' => 1, 'execution_id' => null]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — Old\n- [ ] T-001 — a\n");
    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', '# OLD brief');

    bindArchitect('# FRESH brief');
    capturedRevision($project, ['roadmap_md' => "## M1 — Old\n- [ ] T-001 — a (revised)\n", 'summary' => 'revised']);
    app(ArchitectService::class)->approvePlan($project);

    Livewire::test(ProjectWorkspace::class, ['project' => $project->fresh()])
        ->assertSee('Start build')
        ->assertSee('T-001');
});

test('post-plan the system prompt carries the live plan state, architecture, and decisions (grounding)', function () {
    // M16-D2 root cause: post-plan the roadmap/architecture live in project MEMORY,
    // which the repo-scoped read tools can't reach — so the Architect re-planned
    // blind. The systemPrompt must now inject the DB-derived live plan state.
    $project = Project::factory()->create();
    planned($project);

    // A live plan: M1 BUILT (done task), M2 in progress, M3 not started.
    $m1 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'title' => 'Foundations', 'position' => 1]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m1->id, 'task_key' => 'T-001', 'title' => 'Auth', 'status' => TaskStatus::Approved, 'declared_status' => 'done', 'position' => 1]);
    $m2 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'title' => 'Gameplay', 'position' => 2]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m2->id, 'task_key' => 'T-002', 'title' => 'Loop', 'status' => TaskStatus::Building, 'position' => 1]);
    $m3 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M3', 'title' => 'Polish', 'position' => 3]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m3->id, 'task_key' => 'T-003', 'title' => 'Theme', 'status' => TaskStatus::Pending, 'position' => 1]);

    app(MemoryStore::class)->write($project, 'architecture.md', 'ARCH-DECISION: event-sourced core.');
    app(MemoryStore::class)->write($project, 'decisions.md', 'OWNER-CONSTRAINT: Wayland only.');

    $fake = bindArchitect('Happy to help.'); // plain reply, no tool call
    app(ArchitectService::class)->converse($project, 'How is it going?');

    $system = collect($fake->lastRequest->messages)->firstWhere('role', 'system')['content'];
    expect($system)
        ->toContain('Current plan state')
        ->toContain('M1 — Foundations')
        ->toContain('[x] T-001')            // built task shows done
        ->toContain('BUILT (frozen)')
        ->toContain('M2 — Gameplay')
        ->toContain('[~] T-002')            // in progress
        ->toContain('M3 — Polish')
        ->toContain('[ ] T-003')            // not started
        ->toContain('ARCH-DECISION: event-sourced core.')
        ->toContain('OWNER-CONSTRAINT: Wayland only.');
});

test('an approved revision that drops a built milestone preserves it in DB and the canonical roadmap (freeze)', function () {
    // M16-D2 hard freeze: even a hostile revision that forgets a built milestone
    // entirely may not destroy it — built work is frozen in the DB and re-emitted
    // into the canonical roadmap.md.
    $project = Project::factory()->create();
    $m1 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'title' => 'Foundations', 'position' => 1]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m1->id, 'task_key' => 'T-001', 'title' => 'Auth', 'status' => TaskStatus::Approved, 'declared_status' => 'done', 'position' => 1]);
    $m2 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'title' => 'Gameplay', 'position' => 2]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m2->id, 'task_key' => 'T-002', 'title' => 'Loop', 'status' => TaskStatus::Pending, 'position' => 1]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — Foundations\n- [x] T-001 — Auth\n\n## M2 — Gameplay\n- [ ] T-002 — Loop\n");

    bindArchitect('# fresh brief');
    // The revision FORGOT M1 and re-scoped M2 — exactly the destructive move.
    capturedRevision($project, [
        'roadmap_md' => "## M2 — Gameplay redux\n- [ ] T-002 — Loop\n- [ ] T-003 — Scoring\n",
        'summary' => 'expanded gameplay',
    ]);
    app(ArchitectService::class)->approvePlan($project);

    // M1 + its done task survive verbatim (frozen), the new task landed.
    $built = Task::where('project_id', $project->id)->where('task_key', 'T-001')->first();
    expect($built)->not->toBeNull()
        ->and($built->milestone_id)->toBe($m1->id);
    expect(Milestone::where('project_id', $project->id)->where('milestone_key', 'M1')->exists())->toBeTrue();
    expect(Task::where('project_id', $project->id)->where('task_key', 'T-003')->exists())->toBeTrue();

    // The canonical roadmap.md re-emits the frozen M1 even though the revision dropped it.
    $canonical = app(MemoryStore::class)->read($project, 'roadmap.md');
    expect($canonical)->toContain('## M1 — Foundations')->toContain('T-001 — Auth');
});

test('a revision is blocked while a milestone merge gate is open (ordering guard)', function () {
    // M16-D2 WS3: merging + resuming while a redefine is in flight left two parallel
    // worktrees on divergent bases. A revision must wait for the gate to resolve.
    $project = Project::factory()->create();
    $m1 = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'title' => 'Foundations', 'position' => 1]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m1->id, 'task_key' => 'T-001', 'status' => TaskStatus::Pending, 'position' => 1]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1 — Foundations\n- [ ] T-001 — Auth\n");

    $project->approvals()->create([
        'type' => \App\Enums\ApprovalType::MilestoneMerge,
        'title' => 'Milestone M1 complete',
        'payload' => ['milestone_id' => $m1->id, 'profile' => 'attended'],
        'status' => \App\Enums\ApprovalStatus::Open,
    ]);

    bindArchitect('# fresh brief');
    capturedRevision($project, ['roadmap_md' => "## M1 — Foundations\n- [ ] T-001 — Auth\n- [ ] T-002 — More\n", 'summary' => 'add T-002']);
    app(ArchitectService::class)->approvePlan($project);

    // Blocked: nothing synced, no redefine, a revision_blocked event + system note,
    // roadmap.md untouched.
    expect(Task::where('project_id', $project->id)->where('task_key', 'T-002')->exists())->toBeFalse();
    expect(Event::where('name', 'plan.revision_blocked')->where('project_id', $project->id)->exists())->toBeTrue();
    expect(Event::where('name', 'plan.redefined')->where('project_id', $project->id)->exists())->toBeFalse();
    expect($project->consensusMessages()->where('role', MessageRole::System)->get()
        ->contains(fn ($m) => ($m->meta['revision_blocked'] ?? false) === true))->toBeTrue();
    expect(app(MemoryStore::class)->read($project, 'roadmap.md'))->not->toContain('T-002');
});

test('post-plan composer is one free chat, not steering buttons', function () {
    // M16-B: the two steering buttons are gone — a plan-in-progress keeps the
    // same conversation surface, so a plain reply can continue consensus.
    $project = Project::factory()->create();
    planned($project);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertDontSee('Redefine milestones')
        ->assertSee('Ask a question, add a constraint, or request a change');
});
