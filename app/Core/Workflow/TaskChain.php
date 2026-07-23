<?php

namespace App\Core\Workflow;

use App\Agents\Architect\ArchitectService;
use App\Agents\Reviewer\MilestoneRecap;
use App\Agents\Reviewer\MilestoneReviewOutcome;
use App\Agents\Reviewer\MilestoneReviewService;
use App\Core\Events\EventRecorder;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\CommitService;

/**
 * The autonomy loop (M12): after a task's work lands, advance to the next task
 * in the SAME milestone automatically — decompose its brief, then start its
 * build. When the milestone has no more pending tasks, stop at the milestone
 * boundary (emit `milestone.tasks_complete`); crossing into the next milestone
 * is a gated decision (merge-to-main + start-next), never automatic here.
 *
 * Phase 1 (this): within-milestone auto-advance off the per-task commit.
 * Phase 2 (later): milestone-branch build flow, no per-task gate, milestone
 * merge gate + full_auto auto-merge. See agents/M12-spec.md.
 *
 * Fire-and-forget safe: a failure here must never break the commit that
 * triggered it — callers wrap in try/catch and report.
 */
class TaskChain
{
    public function advance(Task $committed): void
    {
        $milestone = $committed->milestone;
        if ($milestone === null) {
            return; // legacy task with no milestone — nothing to chain
        }

        $project = $committed->project;
        $profile = $committed->execution?->profile ?? 'attended';

        // Next task in this milestone by position: the lowest-position task
        // after the one just committed that hasn't run yet.
        $next = $milestone->tasks()
            ->where('position', '>', $committed->position ?? 0)
            ->where('status', TaskStatus::Pending)
            ->orderBy('position')
            ->first();

        if ($next === null) {
            $this->reachMilestoneBoundary($project, $milestone, $profile);

            return;
        }

        // Generate the next task's brief (no-op if it already has one), then
        // start its build under the same autonomy profile.
        app(ArchitectService::class)->decomposeTask($project, $next);

        app(EventRecorder::class)->record(
            $project,
            'task.autoadvanced',
            ['from' => $committed->task_key, 'to' => $next->task_key],
            null,
            'system'
        );

        ImplementFeatureWorkflow::startForTask($project, $next->task_key, $next->title, $profile);
    }

    /** Owner-locked convergence guard (M15): after this many review→fix rounds
     *  with no approval, escalate instead of looping forever. */
    private const MAX_REVIEW_ROUNDS = 2;

    /**
     * All of a milestone's tasks are done (test-green). M15: the Architect now
     * REVIEWS the milestone's cumulative work here — the right altitude — before
     * any merge. Approve → the human e2e/merge gate (full_auto auto-merges);
     * request_changes → keyed fix-tasks that rebuild and re-review (bounded by
     * MAX_REVIEW_ROUNDS); escalate / stuck → an actionable gate, never a dead end.
     */
    private function reachMilestoneBoundary(Project $project, Milestone $milestone, string $profile): void
    {
        app(EventRecorder::class)->record(
            $project,
            'milestone.tasks_complete',
            ['milestone_key' => $milestone->milestone_key],
            null,
            'system'
        );

        try {
            $outcome = app(MilestoneReviewService::class)->review($milestone);
        } catch (\Throwable $e) {
            report($e);
            // A review that can't run must not stall the roadmap — hand the owner
            // an actionable merge gate that explains why.
            $this->raiseMergeGate($project, $milestone, $profile, "the review could not run ({$e->getMessage()}) — please review and merge manually");

            return;
        }

        if ($outcome->isChanges()) {
            if ($this->changeRounds($project, $milestone) < self::MAX_REVIEW_ROUNDS) {
                $this->requestMilestoneChanges($project, $milestone, $outcome, $profile);

                return;
            }

            // Convergence guard: two fix rounds and still not passing — the brief,
            // not the build, is likely the problem. Escalate; don't loop.
            app(EventRecorder::class)->record($project, 'milestone.review_stuck', ['milestone_key' => $milestone->milestone_key, 'summary' => $outcome->summary], null, 'reviewer');
            $this->raiseMergeGate($project, $milestone, $profile, "the Architect asked for changes twice and it still isn't passing — likely the brief, not the build. Its note: {$outcome->summary}. Merge as-is, or steer it (redefine / chat)", $outcome);

            return;
        }

        if ($outcome->isEscalate()) {
            app(EventRecorder::class)->record($project, 'milestone.review_escalated', ['milestone_key' => $milestone->milestone_key, 'questions' => $outcome->questions], null, 'reviewer');
            $this->raiseMergeGate($project, $milestone, $profile, 'the Architect needs your call — '.trim($outcome->summary.' '.implode(' ', $outcome->questions)), $outcome);

            return;
        }

        // Approved.
        app(EventRecorder::class)->record($project, 'milestone.review_approved', ['milestone_key' => $milestone->milestone_key, 'summary' => $outcome->summary], null, 'reviewer');

        if ($profile === 'full_auto') {
            try {
                app(CommitService::class)->mergeMilestone($milestone);
                $this->startNextMilestone($project, $milestone, $profile);

                return;
            } catch (\Throwable $e) {
                report($e);
                // fall through to the gate below
            }
        }

        $this->raiseMergeGate($project, $milestone, $profile, null, $outcome);
    }

    /** Raise the human milestone-merge gate. $note, when set, explains a review
     *  concern the owner must weigh before merging (never a silent dead end).
     *  $outcome (when the review ran) feeds the recap the gate shows the owner. */
    private function raiseMergeGate(Project $project, Milestone $milestone, string $profile, ?string $note = null, ?MilestoneReviewOutcome $outcome = null): void
    {
        $title = $note !== null
            ? "Milestone {$milestone->milestone_key}: {$note}"
            : "Milestone {$milestone->milestone_key} complete — merge into main + start next";

        // A rich recap — goal, tasks + acceptance criteria, diffstat, review
        // verdict, how-to-test — so the gate is a real review surface, not a
        // blind yes/no (M16-A). Frozen into the payload: the worktree may be gone
        // by the time the owner looks.
        $recap = app(MilestoneRecap::class)->for($milestone, $outcome);

        $project->approvals()->create([
            'type' => ApprovalType::MilestoneMerge,
            'title' => $title,
            'payload' => [
                'milestone_id' => $milestone->id,
                'profile' => $profile,
                'note' => $note,
                'recap' => $recap,
            ],
            'status' => ApprovalStatus::Open,
        ]);
    }

    /** How many change rounds this milestone has already been through. */
    private function changeRounds(Project $project, Milestone $milestone): int
    {
        return $project->events()
            ->where('name', 'milestone.changes_requested')
            ->get()
            ->filter(fn ($e) => ($e->payload['milestone_key'] ?? null) === $milestone->milestone_key)
            ->count();
    }

    /**
     * Turn the review's findings into ONE keyed, observable fix-task (owner
     * decision #3) whose acceptance criteria are the findings, then build it. When
     * it lands, the boundary — and the review — run again.
     */
    private function requestMilestoneChanges(Project $project, Milestone $milestone, MilestoneReviewOutcome $outcome, string $profile): void
    {
        $round = $this->changeRounds($project, $milestone) + 1;
        $key = $this->nextTaskKey($project);
        $position = (int) ($milestone->tasks()->max('position') ?? 0) + 1;

        $task = $milestone->tasks()->create([
            'project_id' => $project->id,
            'task_key' => $key,
            'title' => "Address review findings (round {$round})",
            'position' => $position,
            'status' => TaskStatus::Pending,
        ]);

        $criteria = collect($outcome->items)
            ->map(fn ($i) => '- '.($i['file'] ? "`{$i['file']}`: " : '').$i['reason'])
            ->implode("\n");
        $criteria = $criteria !== '' ? $criteria : '- '.$outcome->summary;

        $brief = "# {$task->title}\n\n## Goal\nResolve the milestone review's findings so {$milestone->milestone_key} — {$milestone->title} meets its goal.\n\n## Acceptance criteria\n{$criteria}\n\n## Notes\nFollow-up fix task from the milestone review. Make the smallest change that resolves each finding; do not touch unrelated code.\n";
        app(MemoryStore::class)->write($project, "tasks/{$key}/task.md", $brief);

        app(EventRecorder::class)->record(
            $project,
            'milestone.changes_requested',
            ['milestone_key' => $milestone->milestone_key, 'round' => $round, 'task_key' => $key, 'findings' => count($outcome->items)],
            null,
            'reviewer'
        );

        ImplementFeatureWorkflow::startForTask($project, $key, $task->title, $profile);
    }

    /**
     * The owner declined the merge WITH a reason (M16-A). Route it to the
     * Architect as ONE keyed fix-task — the smallest change that addresses the
     * feedback — then rebuild; when it lands, the boundary and review run again
     * and raise a fresh gate. Distinct from the reviewer's own request_changes
     * (different event, doesn't count toward the reviewer convergence guard):
     * this is the owner steering, not the loop failing to converge.
     */
    public function requestChangesFromOwner(Project $project, Milestone $milestone, string $reason, string $profile): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            return;
        }

        $key = $this->nextTaskKey($project);
        $position = (int) ($milestone->tasks()->max('position') ?? 0) + 1;

        $task = $milestone->tasks()->create([
            'project_id' => $project->id,
            'task_key' => $key,
            'title' => "Address owner's merge-gate feedback",
            'position' => $position,
            'status' => TaskStatus::Pending,
        ]);

        $brief = "# {$task->title}\n\n## Goal\nAddress the owner's feedback on {$milestone->milestone_key} — {$milestone->title} so it's ready to merge.\n\n## Acceptance criteria\n- {$reason}\n\n## Notes\nThe owner declined the merge with this feedback. Make the smallest change that addresses it; do not touch unrelated code.\n";
        app(MemoryStore::class)->write($project, "tasks/{$key}/task.md", $brief);

        app(EventRecorder::class)->record(
            $project,
            'milestone.owner_changes_requested',
            ['milestone_key' => $milestone->milestone_key, 'task_key' => $key, 'reason' => $reason],
            null,
            'you'
        );

        ImplementFeatureWorkflow::startForTask($project, $key, $task->title, $profile);
    }

    /** Next free T-0NN key across the project. */
    private function nextTaskKey(Project $project): string
    {
        $max = 0;
        foreach ($project->tasks()->pluck('task_key') as $k) {
            if (preg_match('/^T-?(\d+)$/i', (string) $k, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'T-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Decompose + start the first task of the milestone after the given one
     * (by position). No next milestone → the roadmap is complete.
     */
    public function startNextMilestone(Project $project, Milestone $completed, string $profile): void
    {
        $nextMilestone = Milestone::where('project_id', $project->id)
            ->where('position', '>', $completed->position ?? 0)
            ->orderBy('position')
            ->first();

        if ($nextMilestone === null) {
            app(EventRecorder::class)->record($project, 'roadmap.complete', [], null, 'system');

            return;
        }

        $firstTask = $nextMilestone->tasks()
            ->where('status', TaskStatus::Pending)
            ->orderBy('position')
            ->first();

        if ($firstTask === null) {
            return; // nothing pending (already built?) — leave it
        }

        app(ArchitectService::class)->decomposeTask($project, $firstTask);
        app(EventRecorder::class)->record(
            $project,
            'milestone.started',
            ['milestone_key' => $nextMilestone->milestone_key],
            null,
            'system'
        );

        ImplementFeatureWorkflow::startForTask($project, $firstTask->task_key, $firstTask->title, $profile);
    }
}
