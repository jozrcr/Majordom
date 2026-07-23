<?php

namespace App\Core\Workflow;

use App\Core\Events\EventRecorder;
use App\Enums\ImplementationStrategy;
use App\Models\Task;

/**
 * Builder Selection seam (M14b). The single place that records which Builder a
 * task should build under. The Architect (decompose), the escalation menu
 * ("select a stronger Builder"), and the owner UI all route through here so the
 * decision is observable (task.builder_selected) and consistent.
 */
class BuilderSelector
{
    public function assign(Task $task, ImplementationStrategy $strategy, string $by = 'architect'): void
    {
        if ($task->strategy() === $strategy) {
            return; // no-op: don't emit a redundant selection event
        }

        $task->update(['implementation_strategy' => $strategy]);

        app(EventRecorder::class)->record(
            $task->project,
            'task.builder_selected',
            [
                'task_key' => $task->task_key,
                'strategy' => $strategy->value,
                'role' => $strategy->builderRole(),
            ],
            $task->execution,
            $by,
        );
    }
}
