<?php

use App\Agents\Reviewer\MilestoneRecap;
use App\Agents\Reviewer\MilestoneReviewOutcome;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Process;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config(['majordom.memory_root' => sys_get_temp_dir().'/mj-recap-mem-'.uniqid()]);
    $this->wtRoot = sys_get_temp_dir().'/mj-recap-wt-'.uniqid();
    config(['majordom.worktrees_root' => $this->wtRoot]);

    $this->project = Project::factory()->create(['slug' => 'proj']);
    $this->milestone = Milestone::factory()->create([
        'project_id' => $this->project->id, 'milestone_key' => 'M1',
        'title' => 'Skeleton', 'summary' => 'Stand up the shell',
    ]);
    $this->wtPath = $this->wtRoot.'/proj/M1';
    mkdir($this->wtPath, 0777, true);

    Task::factory()->create([
        'project_id' => $this->project->id, 'milestone_id' => $this->milestone->id,
        'task_key' => 'T-001', 'title' => 'First', 'position' => 1, 'base_commit' => 'abc123',
    ]);
    Task::factory()->create([
        'project_id' => $this->project->id, 'milestone_id' => $this->milestone->id,
        'task_key' => 'T-002', 'title' => 'Second', 'position' => 2,
    ]);
    app(MemoryStore::class)->write($this->project, 'tasks/T-001/task.md',
        "# First\n\n## Goal\nDo the thing.\n\n## Acceptance criteria\n- does X\n- does Y\n\n## Notes\nn/a\n");
});

afterEach(function () {
    foreach ([$this->wtRoot, config('majordom.memory_root')] as $d) {
        if (is_string($d) && is_dir($d)) {
            exec('rm -rf '.escapeshellarg($d));
        }
    }
});

it('assembles a merge-gate recap: goal, tasks + criteria, diffstat, branch, verdict, how-to-test', function () {
    Process::fake(['*' => Process::result("3\t1\tsrc/Foo.php\n10\t0\tsrc/Bar.php")]);

    $recap = app(MilestoneRecap::class)->for(
        $this->milestone,
        new MilestoneReviewOutcome('approved', 'meets the goal', howToTest: 'Run `php artisan serve` and click Add.'),
    );

    expect($recap['milestone_key'])->toBe('M1')
        ->and($recap['title'])->toBe('Skeleton')
        ->and($recap['goal'])->toBe('Stand up the shell')
        ->and($recap['branch'])->toBe('majordom/M1')
        ->and($recap['worktree'])->toBe($this->wtPath)
        ->and($recap['review_summary'])->toBe('meets the goal')
        ->and($recap['how_to_test'])->toBe('Run `php artisan serve` and click Add.')
        ->and($recap['diffstat'])->toBe(['files' => 2, 'insertions' => 13, 'deletions' => 1])
        ->and($recap['tasks'])->toHaveCount(2)
        ->and($recap['tasks'][0])->toMatchArray(['key' => 'T-001', 'title' => 'First'])
        ->and($recap['tasks'][0]['criteria'])->toContain('does X')
        ->and($recap['tasks'][0]['criteria'])->toContain('does Y')
        // criteria stops at the next heading — no "Notes" bleed-through
        ->and($recap['tasks'][0]['criteria'])->not->toContain('n/a')
        // a task with no brief carries no invented criteria
        ->and($recap['tasks'][1]['criteria'])->toBeNull();
});

it('falls back to an honest how-to-test when the review supplied none', function () {
    Process::fake(['*' => Process::result('')]);

    $recap = app(MilestoneRecap::class)->for($this->milestone, null);

    expect($recap['review_summary'])->toBeNull()
        ->and($recap['how_to_test'])->toContain('majordom/M1')
        ->and($recap['diffstat'])->toBe(['files' => 0, 'insertions' => 0, 'deletions' => 0]);
});
