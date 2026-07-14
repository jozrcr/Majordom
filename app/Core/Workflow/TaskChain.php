<?php

namespace App\Core\Workflow;

use App\Agents\Architect\ArchitectService;
use App\Core\Events\EventRecorder;
use App\Enums\TaskStatus;
use App\Models\Task;

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
            app(EventRecorder::class)->record(
                $project,
                'milestone.tasks_complete',
                ['milestone_key' => $milestone->milestone_key],
                null,
                'system'
            );

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
}
