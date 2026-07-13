<?php

namespace App\Core\Workflow\Nodes;

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\ApprovalType;
use App\Enums\NodeStatus;
use App\Models\Execution;
use App\Models\Node;
use Illuminate\Support\Facades\Process;

class HumanReviewNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        $task = $execution->tasks()->first();
        if (!$task) {
            return NodeResult::failed('Execution has no task.');
        }

        // nodes() bakes in orderBy('id') ASC — reorder() or the appended
        // DESC sort silently loses and first() returns the OLDEST node.
        $prevNode = $execution->nodes()
            ->whereIn('type', ['build', 'human_task'])
            ->where('status', NodeStatus::Completed)
            ->reorder('id', 'desc')
            ->first();

        $diff = '';
        $filesChanged = [];

        if ($prevNode && isset($prevNode->output['diff'])) {
            $diff = $prevNode->output['diff'];
            $filesChanged = $prevNode->output['filesChanged'] ?? [];
        }

        if ($prevNode && $prevNode->type === 'human_task' && $diff === '' && $task->worktree_path) {
            // Prefer uncommitted work; fall back to the last commit if clean.
            foreach ([['git', 'diff', 'HEAD'], ['git', 'diff', 'HEAD~1', 'HEAD']] as $cmd) {
                $result = Process::path($task->worktree_path)->run($cmd);
                if ($result->successful() && trim($result->output()) !== '') {
                    $diff = $result->output();
                    break;
                }
            }
        }

        if ($diff === '') {
            return NodeResult::failed('No diff to review.');
        }

        return NodeResult::waitHuman(
            ApprovalType::Review,
            "Human review — {$task->task_key}",
            [
                'task_id' => $task->id,
                'diff' => $diff,
                'filesChanged' => $filesChanged,
            ],
            [
                'diff' => $diff,
                'filesChanged' => $filesChanged,
            ]
        );
    }
}
