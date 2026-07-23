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

        // A shared milestone worktree is removed at milestone merge, never
        // per-task — just detach this task from it so a sibling can keep using
        // it (M12). Only per-task (legacy) worktrees are physically removed here.
        if ($task->milestone_id
            && $task->worktree_path === $this->pathForMilestone($task->project, $task->milestone)) {
            $task->worktree_path = null;
            $task->save();

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

    /**
     * Reconcile milestone worktrees after a redefine (M16-C, finding #13). Any
     * milestone whose key the revised roadmap no longer declares is orphaned —
     * remove its worktree AND delete its `majordom/<key>` branch so no stale
     * worktree shadows the active milestone. Best-effort: a single failed cleanup
     * must never raise into the redefine job, so each milestone is guarded and
     * the method returns the keys it actually cleaned.
     *
     * @param array<int, string> $liveKeys milestone keys the revised roadmap keeps
     * @return array<int, string> keys whose worktree/branch were removed
     */
    public function reconcileMilestones(Project $project, array $liveKeys): array
    {
        $repoPath = $project->repo_path;
        if (!is_dir($repoPath) || !is_dir($repoPath.'/.git')) {
            return [];
        }

        $removed = [];
        foreach (Milestone::where('project_id', $project->id)->get() as $milestone) {
            if (in_array($milestone->milestone_key, $liveKeys, true)) {
                continue; // still part of the plan — leave it be
            }

            // M16-D2 freeze: never delete a BUILT milestone's worktree/branch,
            // even if a revision's roadmap omitted its key — that would destroy
            // work not yet merged. Only a not-started milestone is reconcilable.
            if ($milestone->deriveStatus() !== 'todo') {
                continue;
            }

            $path = $this->pathForMilestone($project, $milestone);
            $branch = $this->branchForMilestone($milestone);
            $cleaned = false;

            try {
                if (is_dir($path)) {
                    Process::path($repoPath)->run(['git', 'worktree', 'remove', '--force', $path]);
                    $cleaned = true;
                }

                $verify = Process::path($repoPath)->run(['git', 'rev-parse', '--verify', $branch]);
                if ($verify->successful()) {
                    Process::path($repoPath)->run(['git', 'branch', '-D', $branch]);
                    $cleaned = true;
                }
            } catch (\Throwable) {
                continue; // never let stale-worktree cleanup break the redefine
            }

            if ($cleaned) {
                $removed[] = $milestone->milestone_key;
            }
        }

        if ($removed !== []) {
            Process::path($repoPath)->run(['git', 'worktree', 'prune']);
        }

        return $removed;
    }
}
