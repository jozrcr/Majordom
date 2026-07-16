<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;
use Illuminate\Support\Facades\Process;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** Local twins of DecomposeTaskTest helpers (Pest file-scoped functions don't cross files). */
function groundingArchitect(): object
{
    $fake = new class implements Provider {
        public ?ProviderRequest $lastRequest = null;
        public function chat(ProviderRequest $request): ProviderResponse
        {
            $this->lastRequest = $request;
            return new ProviderResponse(content: "# Brief\n\n## Goal\nx\n", finishReason: 'stop', promptTokens: 1, completionTokens: 1);
        }
    };
    app()->instance(Provider::class, $fake);

    return $fake;
}

function groundingFixture(array $projectAttrs = []): array
{
    setupMemoryRoot();
    $project = Project::factory()->create($projectAttrs);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'title' => 'Skeleton']);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'task_key' => 'T-002',
        'title' => 'Add build system',
        'position' => 2,
        'execution_id' => null,
    ]);
    app(MemoryStore::class)->write($project, 'roadmap.md', "## M1\n- [ ] T-002 — Add build system\n");

    return [$project, $task];
}

// ── RepoIndex ────────────────────────────────────────────────────────────────
// NB: array commands are quoted by Symfony ('git' 'ls-tree' …), so fake with '*'.

test('RepoIndex lists tracked files via git ls-tree', function () {
    Process::fake(['*' => Process::result("app/a.php\napp/b.php\nREADME.md\n")]);

    $out = app(RepoIndex::class)->fileList(sys_get_temp_dir());

    expect($out)->toBe("app/a.php\napp/b.php\nREADME.md");
    Process::assertRan(fn ($process) => in_array('ls-tree', (array) $process->command, true));
});

test('RepoIndex caps the listing and reports the overflow', function () {
    $files = implode("\n", array_map(fn ($i) => "src/f{$i}.php", range(1, 12)));
    Process::fake(['*' => Process::result($files)]);

    $out = app(RepoIndex::class)->fileList(sys_get_temp_dir(), maxFiles: 10);

    expect($out)->toContain('src/f10.php')
        ->toContain('… (+2 more tracked files)')
        ->not->toContain('src/f11.php');
});

test('RepoIndex degrades to null: missing dir, failed git, empty tree', function () {
    expect(app(RepoIndex::class)->fileList('/nonexistent-path-xyz'))->toBeNull();
    expect(app(RepoIndex::class)->fileList(null))->toBeNull();

    Process::fake(['*' => Process::result('', 'fatal: not a git repository', 128)]);
    expect(app(RepoIndex::class)->fileList(sys_get_temp_dir()))->toBeNull();
});

// ── Decompose context grounding (e2e #2) ────────────────────────────────────

test('decompose context declares NONE test command when project has no test runner', function () {
    [$project, $task] = groundingFixture(['test_command' => null]);
    $fake = groundingArchitect();

    app(ArchitectService::class)->decomposeTask($project, $task);

    $userMsg = collect($fake->lastRequest->messages)->firstWhere('role', 'user')['content'];
    expect($userMsg)->toContain('NONE — this project has NO automated test runner')
        ->not->toContain('acceptance criteria may require it to pass');
});

test('decompose context carries the real test command when set', function () {
    [$project, $task] = groundingFixture(['test_command' => 'ninja test']);
    $fake = groundingArchitect();

    app(ArchitectService::class)->decomposeTask($project, $task);

    $userMsg = collect($fake->lastRequest->messages)->firstWhere('role', 'user')['content'];
    expect($userMsg)->toContain('`ninja test` — acceptance criteria may require it to pass')
        ->not->toContain('NO automated test runner');
});

test('decompose context grounds paths in the tracked repo file list', function () {
    $repoPath = sys_get_temp_dir().'/majordom-grounding-'.uniqid();
    mkdir($repoPath, 0755, true);
    [$project, $task] = groundingFixture(['repo_path' => $repoPath]);
    Process::fake(['*' => Process::result("meson.build\nsrc/main.c\n")]);
    $fake = groundingArchitect();

    app(ArchitectService::class)->decomposeTask($project, $task);

    $userMsg = collect($fake->lastRequest->messages)->firstWhere('role', 'user')['content'];
    expect($userMsg)->toContain('## Repository files (tracked — ground your paths in these)')
        ->toContain("meson.build\nsrc/main.c");

    rmdir($repoPath);
});
