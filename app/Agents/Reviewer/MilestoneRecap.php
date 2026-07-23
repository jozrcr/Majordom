<?php

namespace App\Agents\Reviewer;

use App\Models\Milestone;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\MilestoneDiff;
use App\Projects\Repositories\WorktreeManager;

/**
 * The merge gate's recap (M16-A): everything the owner needs to decide "merge
 * this?" without spelunking — the milestone's goal, its tasks and their
 * acceptance criteria, how much it changed (diffstat), the reviewer's verdict,
 * and how to test it end-to-end. Assembled once when the gate is raised and
 * frozen into the approval payload, so the decision surface never depends on a
 * live worktree that may later be merged away.
 */
class MilestoneRecap
{
    public function __construct(
        private readonly MilestoneDiff $diffs,
        private readonly MemoryStore $memory,
        private readonly WorktreeManager $worktrees,
    ) {}

    /**
     * Build the recap for a milestone gate. $outcome (when the review ran)
     * supplies the reviewer's summary and how-to-test; without it (a review that
     * couldn't run) those fall back to honest placeholders.
     *
     * @return array<string, mixed>
     */
    public function for(Milestone $milestone, ?MilestoneReviewOutcome $outcome = null): array
    {
        $project = $milestone->project;
        $goal = trim((string) $milestone->summary);

        $tasks = [];
        foreach ($milestone->tasks()->orderBy('position')->get() as $task) {
            $brief = $this->memory->read($project, "tasks/{$task->task_key}/task.md");
            $tasks[] = [
                'key' => $task->task_key,
                'title' => $task->title,
                'criteria' => $brief !== null ? $this->acceptanceCriteria($brief) : null,
            ];
        }

        return [
            'milestone_key' => $milestone->milestone_key,
            'title' => $milestone->title,
            'goal' => $goal !== '' ? $goal : null,
            'tasks' => $tasks,
            'diffstat' => $this->diffs->stat($milestone),
            'branch' => $this->worktrees->branchForMilestone($milestone),
            'worktree' => $this->diffs->worktree($milestone),
            'review_summary' => $outcome !== null && trim($outcome->summary) !== '' ? trim($outcome->summary) : null,
            'how_to_test' => $outcome?->howToTest ?: $this->fallbackHowToTest($milestone),
        ];
    }

    /**
     * Pull the "## Acceptance criteria" section out of a task brief so the owner
     * sees the bar each task was held to. Returns the section body (trimmed) or
     * null when the brief has no such section.
     */
    private function acceptanceCriteria(string $brief): ?string
    {
        if (! preg_match('/^#+\s*Acceptance criteria\s*$(.*?)(?=^#+\s|\z)/ims', $brief, $m)) {
            return null;
        }

        $body = trim($m[1]);

        return $body !== '' ? $body : null;
    }

    private function fallbackHowToTest(Milestone $milestone): string
    {
        return "Check out branch {$this->worktrees->branchForMilestone($milestone)} and exercise "
            ."the milestone's tasks against their acceptance criteria before merging.";
    }
}
