<?php

use App\Models\Project;
use App\Models\Task;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;
use RuntimeException;

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
