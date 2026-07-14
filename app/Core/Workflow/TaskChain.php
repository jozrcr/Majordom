<?php

namespace App\Core\Workflow;

use App\Agents\Architect\ArchitectService;
use App\Core\Events\EventRecorder;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
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

    /**
     * All of a milestone's tasks are done. full_auto merges to main and rolls
     * into the next milestone unattended; attended/overnight raise a milestone
     * gate (an Approval) for the owner to merge + start the next milestone.
     * The milestone boundary always requires consent EXCEPT under full_auto.
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

        if ($profile === 'full_auto') {
            // Auto-merge; if it fails, fall back to a human gate rather than
            // silently stalling the roadmap.
            try {
                app(CommitService::class)->mergeMilestone($milestone);
                $this->startNextMilestone($project, $milestone, $profile);

                return;
            } catch (\Throwable $e) {
                report($e);
                // fall through to the gate below
            }
        }

        $project->approvals()->create([
            'type' => ApprovalType::MilestoneMerge,
            'title' => "Milestone {$milestone->milestone_key} complete — merge into main + start next",
            'payload' => ['milestone_id' => $milestone->id, 'profile' => $profile],
            'status' => ApprovalStatus::Open,
        ]);
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
