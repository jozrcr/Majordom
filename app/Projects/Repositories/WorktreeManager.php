<?php

namespace App\Projects\Repositories;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class WorktreeManager
{
    public function __construct(
        private readonly string $root
    ) {
    }

    public static function fromConfig(): self
    {
        $root = config('majordom.worktrees_root');
        if (!$root) {
            $home = getenv('HOME') ?: sys_get_temp_dir();
            $root = rtrim($home, '/').'/.majordom/worktrees';
        }
        return new self($root);
    }

    public function pathFor(Task $task): string
    {
        return $this->root.'/'.$task->project->slug.'/'.$task->task_key;
    }

    public function branchFor(Task $task): string
    {
        return 'majordom/'.$task->task_key;
    }

    public function create(Task $task): string
    {
        $repoPath = $task->project->repo_path;
        if (!is_dir($repoPath) || !is_dir($repoPath.'/.git')) {
            throw new RuntimeException("Not a git repository: {$repoPath}");
        }

        if ($task->worktree_path && is_dir($task->worktree_path)) {
            return $task->worktree_path;
        }

        // Unborn HEAD (git init, zero commits): nothing to branch from, and
        // Majordom never creates commits in the user's repo — ask instead.
        $head = Process::path($repoPath)->run(['git', 'rev-parse', '--verify', 'HEAD']);
        if (! $head->successful()) {
            throw new RuntimeException(
                'The repository has no commits yet — make an initial commit, then start the build again.'
            );
        }

        $path = $this->pathFor($task);
        $branch = $this->branchFor($task);

        $dirPath = dirname($path);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $result = Process::path($repoPath)->run([
            'git', 'worktree', 'add', '-b', $branch, $path, 'HEAD'
        ]);

        if (!$result->successful()) {
            $stderr = $result->errorOutput();
            if (str_contains($stderr, 'already exists')) {
                $result = Process::path($repoPath)->run([
                    'git', 'worktree', 'add', $path, $branch
                ]);
                if (!$result->successful()) {
                    throw new RuntimeException("Git worktree add failed: {$result->errorOutput()}");
                }
            } else {
                throw new RuntimeException("Git worktree add failed: {$stderr}");
            }
        }

        $task->branch = $branch;
        $task->worktree_path = $path;
        $task->save();

        return $path;
    }

    public function remove(Task $task): void
    {
        if (!$task->worktree_path) {
            return;
        }

        $repoPath = $task->project->repo_path;
        $result = Process::path($repoPath)->run([
            'git', 'worktree', 'remove', '--force', $task->worktree_path
        ]);

        if (!$result->successful()) {
            throw new RuntimeException("Git worktree remove failed: {$result->errorOutput()}");
        }

        $task->worktree_path = null;
        $task->save();
    }

    public function branchForMilestone(Milestone $m): string
    {
        return 'majordom/'.$m->milestone_key;
    }

    public function pathForMilestone(Project $p, Milestone $m): string
    {
        return $this->root.'/'.$p->slug.'/'.$m->milestone_key;
    }

    public function ensureMilestoneWorktree(Project $p, Milestone $m): string
    {
        $repoPath = $p->repo_path;
        if (!is_dir($repoPath) || !is_dir($repoPath.'/.git')) {
            throw new RuntimeException("Not a git repository: {$repoPath}");
        }

        $path = $this->pathForMilestone($p, $m);
        if (is_dir($path)) {
            return $path;
        }

        $head = Process::path($repoPath)->run(['git', 'rev-parse', '--verify', 'HEAD']);
        if (! $head->successful()) {
            throw new RuntimeException(
                'The repository has no commits yet — make an initial commit, then start the build again.'
            );
        }

        $branch = $this->branchForMilestone($m);
        $dirPath = dirname($path);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $result = Process::path($repoPath)->run([
            'git', 'worktree', 'add', '-b', $branch, $path, 'HEAD'
        ]);

        if (!$result->successful()) {
            $stderr = $result->errorOutput();
            if (str_contains($stderr, 'already exists')) {
                $result = Process::path($repoPath)->run([
                    'git', 'worktree', 'add', $path, $branch
                ]);
                if (!$result->successful()) {
                    throw new RuntimeException("Git worktree add failed: {$result->errorOutput()}");
                }
            } else {
                throw new RuntimeException("Git worktree add failed: {$stderr}");
            }
        }

        return $path;
    }

    public function removeMilestoneWorktree(Project $p, Milestone $m): void
    {
        $path = $this->pathForMilestone($p, $m);
        if (!is_dir($path)) {
            return;
        }

        $repoPath = $p->repo_path;
        $result = Process::path($repoPath)->run([
            'git', 'worktree', 'remove', '--force', $path
        ]);

        if (!$result->successful()) {
            throw new RuntimeException("Git worktree remove failed: {$result->errorOutput()}");
        }
    }
}
