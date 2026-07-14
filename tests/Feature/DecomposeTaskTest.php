<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Models\Event;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** Bind a fake architect Provider returning the given markdown brief; capture the request. */
function fakeArchitect(string $brief): object
{
    $fake = new class($brief) implements Provider {
        public ?ProviderRequest $lastRequest = null;
        public function __construct(public string $brief) {}
        public function chat(ProviderRequest $request): ProviderResponse
        {
            $this->lastRequest = $request;
            return new ProviderResponse(content: $this->brief, finishReason: 'stop', promptTokens: 42, completionTokens: 99);
        }
    };
    app()->instance(Provider::class, $fake);

    return $fake;
}

function decomposeFixture(): array
{
    setupMemoryRoot();
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'milestone_key' => 'M1',
        'title' => 'Skeleton',
        'summary' => 'Stand up the project shell.',
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'task_key' => 'T-002',
        'title' => 'Add build system',
        'position' => 2,
        'execution_id' => null,
    ]);
    $memory = app(MemoryStore::class);
    $memory->write($project, 'roadmap.md', "## M1 — Skeleton\n- [x] T-001 — Repo\n- [ ] T-002 — Add build system\n");
    $memory->write($project, 'architecture.md', 'Laravel app.');

    return [$project, $milestone, $task, $memory];
}

test('decomposeTask writes a task.md brief and emits task.decomposed', function () {
    [$project, , $task, $memory] = decomposeFixture();
    fakeArchitect("# Add build system\n\n## Goal\nSet up meson.\n");

    app(ArchitectService::class)->decomposeTask($project, $task);

    $brief = $memory->read($project, 'tasks/T-002/task.md');
    expect($brief)->toContain('Add build system')->toContain('Set up meson.');
    expect(Event::where('name', 'task.decomposed')->where('project_id', $project->id)->exists())->toBeTrue();
});

test('decomposeTask context carries milestone goal + prior sibling', function () {
    [$project, , $task] = decomposeFixture();
    $fake = fakeArchitect("# Add build system\n\n## Goal\nx\n");

    app(ArchitectService::class)->decomposeTask($project, $task);

    $userMsg = collect($fake->lastRequest->messages)->firstWhere('role', 'user')['content'];
    expect($userMsg)->toContain('T-002 — Add build system')
        ->toContain('Stand up the project shell.') // milestone goal
        ->toContain('T-001'); // prior sibling listed
});

test('decomposeTask is idempotent — skips when a non-empty brief exists', function () {
    [$project, , $task, $memory] = decomposeFixture();
    $memory->write($project, 'tasks/T-002/task.md', '# Existing brief');
    fakeArchitect('# SHOULD NOT BE WRITTEN');

    app(ArchitectService::class)->decomposeTask($project, $task);

    expect($memory->read($project, 'tasks/T-002/task.md'))->toBe('# Existing brief');
    expect(Event::where('name', 'task.decomposed')->exists())->toBeFalse();
});

test('decomposeTask never writes an empty brief; emits decompose_failed', function () {
    [$project, , $task, $memory] = decomposeFixture();
    fakeArchitect('   ');

    app(ArchitectService::class)->decomposeTask($project, $task);

    expect($memory->exists($project, 'tasks/T-002/task.md'))->toBeFalse();
    expect(Event::where('name', 'task.decompose_failed')->exists())->toBeTrue();
});
