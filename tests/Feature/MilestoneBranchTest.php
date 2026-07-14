<?php

namespace Tests\Feature;

use App\Models\Milestone;
use App\Models\Project;
use App\Projects\Repositories\CommitService;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;
use RuntimeException;

uses()->beforeEach(function () {
    config(['majordom.git.author_name' => 'Test User', 'majordom.git.author_email' => 'test@example.com']);
});

test('ensureMilestoneWorktree creates worktree when fresh', function () {
    $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1']);

    $root = sys_get_temp_dir().'/majordom-test-wt';
    $manager = new WorktreeManager($root);
    $expectedPath = $root.'/'.$project->slug.'/'.$milestone->milestone_key;

    Process::fake([
        '*' => Process::result(output: ''),
        ['git', 'rev-parse', '--verify', 'HEAD'] => Process::result(output: 'abc123'),
        ['git', 'worktree', 'add', '-b', 'majordom/M1', $expectedPath, 'HEAD'] => Process::result(output: ''),
    ]);

    $path = $manager->ensureMilestoneWorktree($project, $milestone);

    expect($path)->toBe($expectedPath);
    Process::assertRan(function ($p) use ($expectedPath) {
        return $p->command[0] === 'git' && $p->command[1] === 'worktree' && $p->command[2] === 'add' && $p->command[3] === '-b';
    });
});

test('ensureMilestoneWorktree returns existing path without running git', function () {
    $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2']);

    $root = sys_get_temp_dir().'/majordom-test-wt2';
    $manager = new WorktreeManager($root);
    $expectedPath = $root.'/'.$project->slug.'/'.$milestone->milestone_key;
    mkdir($expectedPath, 0755, true);

    Process::fake();

    $path = $manager->ensureMilestoneWorktree($project, $milestone);

    expect($path)->toBe($expectedPath);
    Process::assertNothingRan();
    
    rmdir($expectedPath);
    rmdir(dirname($expectedPath));
});

test('mergeMilestone happy path records event and removes worktree', function () {
    $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M3', 'title' => 'Feature X']);
    
    $root = sys_get_temp_dir().'/majordom-test-wt3';
    $manager = new WorktreeManager($root);
    $expectedPath = $root.'/'.$project->slug.'/'.$milestone->milestone_key;
    mkdir($expectedPath, 0755, true);

    app()->bind(WorktreeManager::class, fn () => $manager);
    $service = app(CommitService::class);
    
    Process::fake([
        '*' => Process::result(output: ''),
        ['git', 'status', '--porcelain'] => Process::result(output: ''),
        ['git', 'rev-parse', '--verify', 'majordom/M3'] => Process::result(output: 'def456'),
        ['git', 'merge', '--no-ff', 'majordom/M3', '-m', "Merge milestone M3: Feature X"] => Process::result(output: ''),
        ['git', 'worktree', 'remove', '--force', $expectedPath] => Process::result(output: ''),
    ]);

    $service->mergeMilestone($milestone);

    Process::assertRan(function ($p) {
        return $p->command[0] === 'git' && $p->command[1] === 'merge' && $p->command[2] === '--no-ff';
    });
    
    rmdir($expectedPath);
    rmdir(dirname($expectedPath));
});

test('mergeMilestone refuses on dirty tree', function () {
    $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M4']);

    Process::fake([
        '*' => Process::result(output: ''),
        ['git', 'status', '--porcelain'] => Process::result(output: " M file.txt\n"),
    ]);

    $service = app(CommitService::class);
    
    expect(fn() => $service->mergeMilestone($milestone))->toThrow(RuntimeException::class, 'uncommitted changes');
});

test('mergeMilestone throws when branch does not exist', function () {
    $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M5']);

    Process::fake([
        '*' => Process::result(output: ''),
        ['git', 'status', '--porcelain'] => Process::result(output: ''),
        ['git', 'rev-parse', '--verify', 'majordom/M5'] => Process::result(exitCode: 128, errorOutput: 'fatal: Needed a single revision'),
    ]);

    $service = app(CommitService::class);
    
    expect(fn() => $service->mergeMilestone($milestone))->toThrow(RuntimeException::class, 'No milestone branch majordom/M5 to merge.');
});
