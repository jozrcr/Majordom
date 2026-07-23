<?php

namespace App\Projects\Repositories;

use App\Models\Milestone;
use Illuminate\Support\Facades\Process;

/**
 * The one place that answers "what did this milestone change?" (M16-A). The
 * milestone review already needed the cumulative diff; the merge gate needs the
 * same diff plus a diffstat so the owner can SEE what they're merging. Extracting
 * it here keeps a single definition of "the milestone's work" — base_commit..HEAD
 * on majordom/<key> — behind three readers. Reads only; never mutates the repo.
 */
class MilestoneDiff
{
    public function __construct(
        private readonly WorktreeManager $worktrees,
    ) {}

    /** The milestone worktree path if it exists on disk, else null. */
    public function worktree(Milestone $milestone): ?string
    {
        $path = $this->worktrees->pathForMilestone($milestone->project, $milestone);
        if (is_dir($path)) {
            return $path;
        }

        // Fall back to a task's recorded worktree (older milestones).
        $task = $milestone->tasks()->whereNotNull('worktree_path')->first();

        return $task && is_dir((string) $task->worktree_path) ? (string) $task->worktree_path : null;
    }

    /**
     * The milestone's cumulative diff: base_commit..HEAD across the whole
     * milestone (its fork point is the first task's base). Empty string when
     * there's no worktree or nothing changed.
     */
    public function cumulative(Milestone $milestone): string
    {
        $worktree = $this->worktree($milestone);
        if ($worktree === null) {
            return '';
        }

        $base = $this->base($milestone);

        if ($base !== null) {
            $result = Process::path($worktree)->run(['git', 'diff', $base]);
            if ($result->successful()) {
                return trim($result->output());
            }
        }

        // Fallback: everything the milestone branch added since it forked from main.
        $result = Process::path($worktree)->run(['git', 'diff', 'main...HEAD']);

        return $result->successful() ? trim($result->output()) : '';
    }

    /**
     * Diffstat for the merge gate — how much this milestone touches, at a glance.
     *
     * @return array{files: int, insertions: int, deletions: int}
     */
    public function stat(Milestone $milestone): array
    {
        $empty = ['files' => 0, 'insertions' => 0, 'deletions' => 0];

        $worktree = $this->worktree($milestone);
        if ($worktree === null) {
            return $empty;
        }

        $base = $this->base($milestone);
        $range = $base !== null ? [$base] : ['main...HEAD'];

        $result = Process::path($worktree)->run(array_merge(['git', 'diff', '--numstat'], $range));
        if (! $result->successful()) {
            return $empty;
        }

        $files = 0;
        $insertions = 0;
        $deletions = 0;
        foreach (explode("\n", trim($result->output())) as $line) {
            if (trim($line) === '') {
                continue;
            }
            // "<added>\t<removed>\t<path>" — binary files report "-\t-".
            $parts = preg_split('/\t/', $line);
            if ($parts === false || count($parts) < 3) {
                continue;
            }
            $files++;
            $insertions += is_numeric($parts[0]) ? (int) $parts[0] : 0;
            $deletions += is_numeric($parts[1]) ? (int) $parts[1] : 0;
        }

        return ['files' => $files, 'insertions' => $insertions, 'deletions' => $deletions];
    }

    /** The milestone's fork point: its first task's recorded base commit, or null. */
    private function base(Milestone $milestone): ?string
    {
        $base = $milestone->tasks()->whereNotNull('base_commit')->orderBy('position')->value('base_commit');

        return $base !== null && trim((string) $base) !== '' ? (string) $base : null;
    }
}
