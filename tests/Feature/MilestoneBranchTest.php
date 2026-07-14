<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Projects\Repositories\CommitService;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // committerEnv() reads this first, so no git-config subprocess under fake.
    config(['majordom.git.author_name' => 'Test User', 'majordom.git.author_email' => 'test@example.com']);
});

/** A throwaway dir that looks like a git repo (has a .git dir). */
function fakeRepo(): string
{
    $dir = sys_get_temp_dir().'/majordom-mb-'.uniqid();
    mkdir($dir.'/.git', 0755, true);
    return $dir;
}

test('ensureMilestoneWorktree creates the worktree on majordom/<key> when fresh', function () {
    $repo = fakeRepo();
    $project = Project::factory()->create(['repo_path' => $repo]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1']);

    $root = sys_get_temp_dir().'/majordom-wt-'.uniqid();
    $manager = new WorktreeManager($root);
    $expected = $root.'/'.$project->slug.'/M1';

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
        "'git' 'worktree' 'add' '-b' 'majordom/M1'*" => Process::result(output: 'ok'),
    ]);

    expect($manager->ensureMilestoneWorktree($project, $milestone))->toBe($expected);
    Process::assertRan(fn ($p) => is_array($p->command)
        && $p->command === ['git', 'worktree', 'add', '-b', 'majordom/M1', $expected, 'HEAD']);
});

test('ensureMilestoneWorktree reuses an existing worktree without running git', function () {
    $repo = fakeRepo();
    $project = Project::factory()->create(['repo_path' => $repo]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M2']);

    $root = sys_get_temp_dir().'/majordom-wt-'.uniqid();
    $manager = new WorktreeManager($root);
    $expected = $root.'/'.$project->slug.'/M2';
    mkdir($expected, 0755, true); // worktree already present

    Process::fake();

    expect($manager->ensureMilestoneWorktree($project, $milestone))->toBe($expected);
    Process::assertNothingRan();
});

test('mergeMilestone merges --no-ff, records the event, removes the worktree', function () {
    $repo = fakeRepo();
    $project = Project::factory()->create(['repo_path' => $repo]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M3', 'title' => 'Feature X']);

    $root = sys_get_temp_dir().'/majordom-wt-'.uniqid();
    $expected = $root.'/'.$project->slug.'/M3';
    mkdir($expected, 0755, true);
    app()->instance(WorktreeManager::class, new WorktreeManager($root));

    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: ''),
        "'git' 'rev-parse' '--verify' 'majordom/M3'" => Process::result(output: "def456\n"),
        "'git' 'merge' '--no-ff' 'majordom/M3'*" => Process::result(output: 'merged'),
        "'git' 'worktree' 'remove' '--force'*" => Process::result(output: ''),
    ]);

    app(CommitService::class)->mergeMilestone($milestone);

    expect(\App\Models\Event::where('name', 'milestone.merged')->where('project_id', $project->id)->exists())->toBeTrue();
    Process::assertRan(fn ($p) => is_array($p->command) && $p->command[1] === 'merge' && $p->command[2] === '--no-ff');
    Process::assertRan(fn ($p) => is_array($p->command) && $p->command === ['git', 'worktree', 'remove', '--force', $expected]);
});

test('mergeMilestone refuses on a dirty tree', function () {
    $repo = fakeRepo();
    $project = Project::factory()->create(['repo_path' => $repo]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M4']);

    Process::fake(["'git' 'status' '--porcelain'" => Process::result(output: " M file.txt\n")]);

    expect(fn () => app(CommitService::class)->mergeMilestone($milestone))
        ->toThrow(RuntimeException::class, 'uncommitted changes');
});

test('mergeMilestone throws when the milestone branch does not exist', function () {
    $repo = fakeRepo();
    $project = Project::factory()->create(['repo_path' => $repo]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M5']);

    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: ''),
        "'git' 'rev-parse' '--verify' 'majordom/M5'" => Process::result(exitCode: 128, errorOutput: 'fatal: bad revision'),
    ]);

    expect(fn () => app(CommitService::class)->mergeMilestone($milestone))
        ->toThrow(RuntimeException::class, 'No milestone branch majordom/M5 to merge.');
});
