<?php

namespace App\Core\Workflow\Nodes;

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\ApprovalType;
use App\Models\Execution;
use App\Models\Node;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\WorktreeManager;

class HumanTaskNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        $task = $execution->tasks()->first();
        if (!$task) {
            return NodeResult::failed('Execution has no task.');
        }

        try {
            $path = app(WorktreeManager::class)->create($task);
        } catch (\Throwable $e) {
            return NodeResult::failed('Failed to create worktree: '.$e->getMessage());
        }

        $memory = app(MemoryStore::class);
        $taskKey = $task->task_key;
        $briefPath = $task->revision > 1
            ? "tasks/{$taskKey}/task.v{$task->revision}.md"
            : "tasks/{$taskKey}/task.md";
        $brief = $memory->read($task->project, $briefPath) ?? '';
        $brief = mb_substr($brief, 0, 2000);

        $instructions = trim((string) ($node->input['config']['instructions'] ?? ''));
        if ($instructions !== '') {
            $brief = trim($brief."\n\n**Step instructions:** ".mb_substr($instructions, 0, 500));
        }

        return NodeResult::waitHuman(
            ApprovalType::HumanTask,
            "Your turn — {$taskKey}: {$task->title}",
            [
                'task_id' => $task->id,
                'worktree' => $path,
                'branch' => $task->branch,
                'brief' => $brief,
            ],
            [
                'task_id' => $task->id,
                'worktree' => $path,
                'branch' => $task->branch,
                'brief' => $brief,
            ]
        );
    }
}
