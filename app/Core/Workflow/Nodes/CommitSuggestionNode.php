<?php

namespace App\Core\Workflow\Nodes;

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\CommitSuggestion;
use App\Models\Execution;
use App\Models\Node;

/**
 * The chain's last node (SPEC §3 phase 9, M3 slice): prepare a
 * CommitSuggestion from the approved diff. Deliberately template-based —
 * no provider call — and it never runs git commit: promotion into the
 * user's history is the human's act alone.
 */
class CommitSuggestionNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        $task = $execution->tasks()->first();
        if ($task === null) {
            return NodeResult::failed('Execution has no task.');
        }

        $buildNode = $execution->nodes()
            ->where('type', 'build')
            ->where('status', NodeStatus::Completed)
            ->orderByDesc('id')
            ->first();

        $diff = $buildNode->output['diff'] ?? '';
        $summary = $buildNode->output['summary'] ?? '';

        $reviewNode = $execution->nodes()
            ->where('type', 'review')
            ->orderByDesc('id')
            ->first();
        $reviewSummary = $reviewNode->output['verdict']['summary'] ?? '';

        $message = "feat({$task->task_key}): {$task->title}\n\n"
            .trim($summary."\n\n".$reviewSummary)
            ."\n\nBuilt by Majordom (Builder: local Qwen via aider; reviewed and human-approved).";

        $suggestion = CommitSuggestion::create([
            'project_id' => $execution->project_id,
            'execution_id' => $execution->id,
            'task_id' => $task->id,
            'message' => $message,
            'diff' => $diff,
            'branch' => $task->branch,
            'status' => 'suggested',
        ]);

        $task->update(['status' => TaskStatus::Approved]);

        return NodeResult::done(['commit_suggestion_id' => $suggestion->id]);
    }
}
