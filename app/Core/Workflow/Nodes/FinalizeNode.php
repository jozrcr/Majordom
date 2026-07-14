<?php

namespace App\Core\Workflow\Nodes;

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;

/**
 * The chain's terminal node in the M12 auto-commit flow (SPEC §3 phase 9,
 * milestone-branch model). The Builder's work is already committed to the
 * milestone branch (majordom/<key>) during build, so there is nothing to
 * promote per-task — this just marks the task done. The autonomy loop
 * (TaskChain) advances from execution completion.
 *
 * When a project opts into a per-task checkpoint (`confirm_commits`), the chain
 * uses `commit_suggestion` (a human diff-review gate) in place of this node.
 */
class FinalizeNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        $task = $execution->tasks()->first();
        if ($task === null) {
            return NodeResult::failed('Execution has no task.');
        }

        $task->update(['status' => TaskStatus::Approved]);

        return NodeResult::done(['task_key' => $task->task_key]);
    }
}
