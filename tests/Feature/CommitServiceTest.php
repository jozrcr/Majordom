<?php

use App\Enums\TaskStatus;
use App\Models\CommitSuggestion;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\CommitService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['majordom.memory_root' => sys_get_temp_dir().'/majordom-commit-'.uniqid()]);
    // committerEnv() reads this first, so the git-config subprocess isn't needed under Process::fake.
    config(['majordom.git.author_name' => 'Test User', 'majordom.git.author_email' => 'test@example.com']);

    $this->project = Project::factory()->create(['repo_path' => '/tmp/fake-repo']);
    $this->execution = Execution::factory()->create(['project_id' => $this->project->id]);
    $this->task = Task::factory()->create([
        'project_id' => $this->project->id,
        'execution_id' => $this->execution->id,
        'task_key' => 'T-001',
        'title' => 'Guard divide',
        'branch' => 'majordom/T-001',
        'worktree_path' => '/tmp/fake-worktree',
        'revision' => 1,
    ]);
    $this->suggestion = CommitSuggestion::create([
        'project_id' => $this->project->id,
        'execution_id' => $this->execution->id,
        'task_id' => $this->task->id,
        'message' => "feat(T-001): guard divide\n\nBody.",
        'diff' => 'diff --git …',
        'branch' => 'majordom/T-001',
        'status' => 'suggested',
    ]);
});

it('applies: clean tree → squash merge → commit → worktree removed', function () {
    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: ''),
        "'git' 'merge' '--squash' 'majordom/T-001'" => Process::result(output: 'Squash ok'),
        "'git' 'commit' '-m'*" => Process::result(output: 'committed'),
        "'git' 'worktree' 'remove'*" => Process::result(output: ''),
    ]);

    app(CommitService::class)->apply($this->suggestion);

    expect($this->suggestion->fresh()->status)->toBe('committed')
        ->and($this->task->fresh()->worktree_path)->toBeNull();

    Process::assertRan(fn ($p) => $p->path === '/tmp/fake-repo'
        && is_array($p->command) && $p->command[1] === 'merge');
});

it('refuses to commit over a dirty working tree', function () {
    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: " M app/Something.php\n"),
    ]);

    expect(fn () => app(CommitService::class)->apply($this->suggestion))
        ->toThrow(RuntimeException::class, 'uncommitted changes');

    expect($this->suggestion->fresh()->status)->toBe('suggested');
});

it('resets the staged squash when the commit itself fails', function () {
    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: ''),
        "'git' 'merge' '--squash'*" => Process::result(output: 'ok'),
        "'git' 'commit' '-m'*" => Process::result(exitCode: 1, errorOutput: 'hook rejected'),
        "'git' 'reset' '--hard' 'HEAD'" => Process::result(output: ''),
        "'git' 'clean' '-fd'" => Process::result(output: ''),
    ]);

    expect(fn () => app(CommitService::class)->apply($this->suggestion))
        ->toThrow(RuntimeException::class, 'hook rejected');

    Process::assertRan(fn ($p) => is_array($p->command) && $p->command === ['git', 'reset', '--hard', 'HEAD']);
    expect($this->suggestion->fresh()->status)->toBe('suggested');
});

it('rework writes the owner comment as the next revision brief and restarts', function () {
    app(MemoryStore::class)->write($this->project, 'tasks/T-001/task.md', 'Original brief');

    $task = app(CommitService::class)->rework($this->suggestion, 'Rename the flag to --log-file');

    $brief = app(MemoryStore::class)->read($this->project, 'tasks/T-001/task.v2.md');
    expect($brief)->toContain('Original brief')
        ->and($brief)->toContain('Owner rework request')
        ->and($brief)->toContain('--log-file')
        ->and($this->suggestion->fresh()->status)->toBe('discarded')
        ->and($task->id)->toBe($this->task->id) // task reused, revision preserved
        ->and($task->revision)->toBe(2)
        ->and($this->project->executions()->count())->toBe(2);

    Queue::assertPushed(\App\Core\Workflow\Nodes\DelegateNode::class);
});

it('reject discards, fails the task, removes the worktree, keeps the branch', function () {
    Process::fake(["'git' 'worktree' 'remove'*" => Process::result(output: '')]);

    app(CommitService::class)->reject($this->suggestion, 'Wrong direction entirely.');

    expect($this->suggestion->fresh()->status)->toBe('discarded')
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($this->task->fresh()->worktree_path)->toBeNull();

    Process::assertNotRan(fn ($p) => is_array($p->command) && in_array('branch', $p->command));
});

it('refuses to act twice on the same suggestion', function () {
    $this->suggestion->update(['status' => 'committed']);

    expect(fn () => app(CommitService::class)->reject($this->suggestion, 'x'))
        ->toThrow(RuntimeException::class, 'already resolved');
});

it('ignores untracked files in the clean-tree guard', function () {
    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: "?? __pycache__/\n?? notes.txt\n"),
        "'git' 'merge' '--squash'*" => Process::result(output: 'ok'),
        "'git' 'commit' '-m'*" => Process::result(output: 'committed'),
        "'git' 'worktree' 'remove'*" => Process::result(output: ''),
    ]);

    app(CommitService::class)->apply($this->suggestion);

    expect($this->suggestion->fresh()->status)->toBe('committed');
});

it('errors clearly when no git identity is resolvable', function () {
    config(['majordom.git.author_name' => null, 'majordom.git.author_email' => null]);
    Process::fake([
        "'git' 'status' '--porcelain'" => Process::result(output: ''),
        "'git' 'merge' '--squash'*" => Process::result(output: 'ok'),
        "'git' 'config' 'user.name'" => Process::result(output: ''),
        "'git' 'config' 'user.email'" => Process::result(output: ''),
    ]);

    expect(fn () => app(CommitService::class)->apply($this->suggestion))
        ->toThrow(RuntimeException::class, 'No git identity');
});
