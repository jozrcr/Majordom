<?php

namespace App\Core\Workflow\Nodes;

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\WorktreeManager;

class DelegateNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        /** @var Task|null $task */
        $task = $execution->tasks()->first();
        if (!$task) {
            return NodeResult::failed('Execution has no task.');
        }

        $memory = app(MemoryStore::class);
        $project = $task->project;
        $taskKey = $task->task_key;
        $rolePath = "tasks/{$taskKey}/role.md";
        $taskPath = "tasks/{$taskKey}/task.md";

        if (!$memory->exists($project, $rolePath)) {
            $memory->write($project, $rolePath, <<<MD
You are the Builder: a careful software engineer implementing exactly one scoped task.
Rules: make the smallest change that satisfies the acceptance criteria; follow the
existing code style; do not refactor unrelated code; do not touch files outside the
task's scope; never push. If the task is impossible as specified, say so plainly.
MD
            );
        }

        if (trim((string) $memory->read($project, $taskPath)) === '') {
            return NodeResult::failed("Task brief at tasks/{$taskKey}/task.md is missing or empty — re-run planning (approve the plan again in the chat).");
        }

        try {
            $path = app(WorktreeManager::class)->create($task);
        } catch (\Throwable $e) {
            return NodeResult::failed('Failed to create worktree: '.$e->getMessage());
        }

        $task->status = TaskStatus::Building;
        $task->save();

        return NodeResult::done([
            'task_id' => $task->id,
            'worktree' => $path,
            'branch' => $task->branch,
        ]);
    }
}
