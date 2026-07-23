<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('creates worktree and persists branch and path', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir, 0755, true);
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id]);

    Process::fake();

    $manager = app(WorktreeManager::class);
    $expectedPath = $manager->pathFor($task);

    $path = $manager->create($task);

    expect($path)->toBe($expectedPath);
    $task->refresh();
    expect($task->branch)->toBe('majordom/T-001')
        ->and($task->worktree_path)->toBe($expectedPath);

    Process::assertRan(function ($run) use ($repoDir, $expectedPath) {
        return $run->path === $repoDir &&
               $run->command === ['git', 'worktree', 'add', '-b', 'majordom/T-001', $expectedPath, 'HEAD'];
    });
});

it('reuses existing worktree_path without running git', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir, 0755, true);
    mkdir($repoDir.'/.git', 0755, true);

    $existingPath = sys_get_temp_dir().'/majordom-existing-'.uniqid();
    mkdir($existingPath, 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id, 'worktree_path' => $existingPath]);

    Process::fake();
    $manager = app(WorktreeManager::class);
    $path = $manager->create($task);

    expect($path)->toBe($existingPath);
    Process::assertNothingRan();
});

it('retries without -b on already exists stderr', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir, 0755, true);
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id]);

    $manager = app(WorktreeManager::class);
    $expectedPath = $manager->pathFor($task);

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
        "'git' 'worktree' 'add'*" => Process::sequence()
            ->push(Process::result(exitCode: 128, errorOutput: 'fatal: a branch named majordom/T-001 already exists'))
            ->push(Process::result(exitCode: 0)),
    ]);

    $path = $manager->create($task);

    expect($path)->toBe($expectedPath);
    $task->refresh();
    expect($task->branch)->toBe('majordom/T-001')
        ->and($task->worktree_path)->toBe($expectedPath);

    Process::assertRan(function ($run) use ($expectedPath) {
        return $run->command === ['git', 'worktree', 'add', '-b', 'majordom/T-001', $expectedPath, 'HEAD'];
    });
    Process::assertRan(function ($run) use ($expectedPath) {
        return $run->command === ['git', 'worktree', 'add', $expectedPath, 'majordom/T-001'];
    });
});

it('throws on non-git repo_path', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir, 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id]);

    Process::fake();
    $manager = app(WorktreeManager::class);

    expect(fn () => $manager->create($task))
        ->toThrow(RuntimeException::class, 'Not a git repository: '.$repoDir);

    Process::assertNothingRan();
});

it('throws on other git failure with stderr', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir, 0755, true);
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id]);

    $manager = app(WorktreeManager::class);

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
        "'git' 'worktree' 'add'*" => Process::result(exitCode: 1, errorOutput: 'fatal: some other error'),
    ]);

    expect(fn () => $manager->create($task))
        ->toThrow(RuntimeException::class, 'Git worktree add failed: fatal: some other error');
});

it('removes worktree and nulls path', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir, 0755, true);
    mkdir($repoDir.'/.git', 0755, true);

    $worktreePath = sys_get_temp_dir().'/majordom-worktree-'.uniqid();
    mkdir($worktreePath, 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id, 'worktree_path' => $worktreePath]);

    Process::fake();
    $manager = app(WorktreeManager::class);
    $manager->remove($task);

    $task->refresh();
    expect($task->worktree_path)->toBeNull();

    Process::assertRan(function ($run) use ($repoDir, $worktreePath) {
        return $run->path === $repoDir &&
               $run->command === ['git', 'worktree', 'remove', '--force', $worktreePath];
    });
});

it('does nothing on remove with null worktree_path', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir, 0755, true);
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id]);

    Process::fake();
    $manager = app(WorktreeManager::class);
    $manager->remove($task);

    Process::assertNothingRan();
});

it('refuses a repo with no commits with a human message', function () {
    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'test-project', 'repo_path' => $repoDir]);
    $task = Task::factory()->create(['task_key' => 'T-001', 'project_id' => $project->id]);

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(exitCode: 128, errorOutput: 'fatal: Needed a single revision'),
    ]);

    expect(fn () => app(WorktreeManager::class)->create($task))
        ->toThrow(RuntimeException::class, 'no commits yet');
});

it('reconciles milestone worktrees — removes the orphan, leaves the live one', function () {
    $root = sys_get_temp_dir().'/majordom-wt-root-'.uniqid();
    config(['majordom.worktrees_root' => $root]);

    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'proj', 'repo_path' => $repoDir]);
    $live = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1', 'position' => 1]);
    $dropped = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'position' => 2]);

    $manager = WorktreeManager::fromConfig();
    $livePath = $manager->pathForMilestone($project, $live);
    $droppedPath = $manager->pathForMilestone($project, $dropped);
    mkdir($livePath, 0755, true);
    mkdir($droppedPath, 0755, true);

    // Bare fake → a `*` catch-all that fakes AND records every git command as a
    // success (a keyed fake would let unlisted commands run for real, unrecorded).
    Process::fake();

    $removed = $manager->reconcileMilestones($project, ['M1']);

    expect($removed)->toBe(['M2']);

    // The orphaned milestone's worktree and branch are both gone.
    Process::assertRan(fn ($run) => $run->path === $repoDir
        && $run->command === ['git', 'worktree', 'remove', '--force', $droppedPath]);
    Process::assertRan(fn ($run) => $run->command === ['git', 'branch', '-D', 'majordom/M2']);
    Process::assertRan(fn ($run) => $run->command === ['git', 'worktree', 'prune']);

    // The live milestone is never touched.
    Process::assertDidntRun(fn ($run) => in_array($livePath, $run->command, true));
    Process::assertDidntRun(fn ($run) => $run->command === ['git', 'branch', '-D', 'majordom/M1']);
});

it('reconcile deletes an orphan branch even when its worktree dir is already gone', function () {
    $root = sys_get_temp_dir().'/majordom-wt-root-'.uniqid();
    config(['majordom.worktrees_root' => $root]);

    $repoDir = sys_get_temp_dir().'/majordom-test-repo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);

    $project = Project::factory()->create(['slug' => 'proj', 'repo_path' => $repoDir]);
    $dropped = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'position' => 1]);

    // No worktree dir on disk — only the branch remains. Bare fake so rev-parse
    // reports the branch exists and the delete is recorded.
    Process::fake();

    $removed = WorktreeManager::fromConfig()->reconcileMilestones($project, []);

    expect($removed)->toBe(['M2']);
    Process::assertRan(fn ($run) => $run->command === ['git', 'branch', '-D', 'majordom/M2']);
    Process::assertDidntRun(fn ($run) => ($run->command[1] ?? null) === 'worktree' && ($run->command[2] ?? null) === 'remove');
});

it('reconcile is a no-op on a non-git repo path', function () {
    $project = Project::factory()->create(['slug' => 'proj', 'repo_path' => '/no/such/repo-'.uniqid()]);
    Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2', 'position' => 1]);

    Process::fake();

    expect(WorktreeManager::fromConfig()->reconcileMilestones($project, []))->toBe([]);
    Process::assertNothingRan();
});
