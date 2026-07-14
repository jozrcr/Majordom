<?php

namespace App\Projects\Repositories;

use App\Core\Events\EventRecorder;
use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Enums\TaskStatus;
use App\Models\CommitSuggestion;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The human's commit verdict, executed (SPEC §3 phase 9). Every entry point
 * here runs because the owner clicked — Majordom never invokes this on its
 * own. Push stays out entirely.
 */
class CommitService
{
    public function __construct(
        private readonly WorktreeManager $worktrees,
        private readonly MemoryStore $memory,
        private readonly EventRecorder $events,
    ) {}

    /** Squash-promote the WIP branch into the user's checkout and commit. */
    public function apply(CommitSuggestion $suggestion): void
    {
        $this->assertSuggested($suggestion);
        $task = $suggestion->task;
        $repo = $suggestion->project->repo_path;

        // Untracked files can't be clobbered by a squash-merge (git aborts
        // itself on a real path collision) — only TRACKED changes block.
        $status = Process::path($repo)->run(['git', 'status', '--porcelain']);
        $dirty = collect(explode("\n", trim($status->output())))
            ->filter(fn ($line) => $line !== '' && ! str_starts_with($line, '??'));
        if ($dirty->isNotEmpty()) {
            throw new RuntimeException('Your working tree has uncommitted changes — commit or stash them first.');
        }

        $merge = Process::path($repo)->run(['git', 'merge', '--squash', $suggestion->branch]);
        if (! $merge->successful()) {
            throw new RuntimeException('Squash merge failed: '.trim($merge->errorOutput()));
        }

        $commit = Process::path($repo)
            ->env($this->committerEnv($repo))
            ->run(['git', 'commit', '-m', $suggestion->message]);
        if (! $commit->successful()) {
            // Fully undo the staged squash so the checkout is left as found.
            // `git reset --merge` does NOT unwind a --squash (there is no
            // MERGE_HEAD), which stranded the staged files and blocked every
            // retry with a false "dirty tree" — reset hard + drop SQUASH_MSG.
            // Safe: the dirty-tree guard above proved the tree was clean, so
            // the only changes here are our own squash, and every file is on
            // the feature branch.
            Process::path($repo)->run(['git', 'reset', '--hard', 'HEAD']);
            Process::path($repo)->run(['git', 'clean', '-fd']);
            @unlink(rtrim($repo, '/').'/.git/SQUASH_MSG');
            throw new RuntimeException('Commit failed: '.trim($commit->errorOutput()));
        }

        $suggestion->update(['status' => 'committed']);
        if ($task) {
            $this->worktrees->remove($task); // branch kept for archaeology
        }

        $this->events->record($suggestion->project, 'commit.applied', [
            'branch' => $suggestion->branch,
            'task_key' => $task?->task_key,
        ], $suggestion->execution, 'you');
    }

    /** Owner comments become the next revision brief; a new run starts. */
    public function rework(CommitSuggestion $suggestion, string $comment): Task
    {
        $this->assertSuggested($suggestion);
        $task = $suggestion->task ?? throw new RuntimeException('Suggestion has no task.');
        $project = $suggestion->project;

        $base = $this->memory->read($project, "tasks/{$task->task_key}/task.md") ?? '';
        $next = $task->revision + 1;
        $this->memory->write(
            $project,
            "tasks/{$task->task_key}/task.v{$next}.md",
            $base."\n\n## Owner rework request (revision {$next})\n\n{$comment}\n",
        );
        $task->update(['revision' => $next]);
        $suggestion->update(['status' => 'discarded']);

        $this->events->record($project, 'commit.reworked', [
            'task_key' => $task->task_key, 'comment' => $comment, 'revision' => $next,
        ], $suggestion->execution, 'you');

        return ImplementFeatureWorkflow::startForTask($project, $task->task_key, $task->title);
    }

    /** Discard entirely: suggestion dead, worktree gone, branch kept. */
    public function reject(CommitSuggestion $suggestion, string $comment): void
    {
        $this->assertSuggested($suggestion);
        $suggestion->update(['status' => 'discarded']);

        if ($task = $suggestion->task) {
            $task->update(['status' => TaskStatus::Failed]);
            $this->worktrees->remove($task);
        }

        $this->events->record($suggestion->project, 'commit.rejected', [
            'task_key' => $suggestion->task?->task_key, 'comment' => $comment,
        ], $suggestion->execution, 'you');
    }

    private function assertSuggested(CommitSuggestion $suggestion): void
    {
        if ($suggestion->status !== 'suggested') {
            throw new RuntimeException('This suggestion was already resolved.');
        }
    }

    /**
     * Committer identity, passed explicitly so the commit never depends on the
     * app process's ambient $HOME (snap/systemd can hide ~/.gitconfig, which is
     * why git reports "unknown author"). Prefer the configured Majordom
     * identity; else the repo's own resolved git identity; else fail loudly.
     *
     * @return array<string, string>
     */
    private function committerEnv(string $repo): array
    {
        // Under snap/systemd the ambient $HOME can point at a sandboxed dir,
        // hiding the real ~/.gitconfig so git can't resolve identity. Recover
        // the real home from the passwd DB and run git config against it.
        $realHome = getenv('HOME') ?: null;
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = @posix_getpwuid(posix_getuid());
            if (! empty($pw['dir'])) {
                $realHome = $pw['dir'];
            }
        }
        $homeEnv = $realHome ? ['HOME' => $realHome] : [];

        $name = config('majordom.git.author_name')
            ?: trim(Process::path($repo)->env($homeEnv)->run(['git', 'config', 'user.name'])->output());
        $email = config('majordom.git.author_email')
            ?: trim(Process::path($repo)->env($homeEnv)->run(['git', 'config', 'user.email'])->output());

        if ($name === '' || $email === '') {
            throw new RuntimeException(
                'No git identity available for the commit. Set MAJORDOM_GIT_AUTHOR_NAME '
                .'and MAJORDOM_GIT_AUTHOR_EMAIL in .env, or run `git config --global '
                .'user.name`/`user.email` (the app process may run under a sandboxed $HOME).'
            );
        }

        return $homeEnv + [
            'GIT_AUTHOR_NAME' => $name,
            'GIT_AUTHOR_EMAIL' => $email,
            'GIT_COMMITTER_NAME' => $name,
            'GIT_COMMITTER_EMAIL' => $email,
        ];
    }
}
