<?php

namespace App\Core\Workflow\Nodes;

use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Process;

class TestNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        /** @var Task|null $task */
        $task = $execution->tasks()->first();
        if (!$task) {
            return NodeResult::failed('Execution has no task.');
        }

        $command = $task->project->test_command;

        if ($command === null) {
            $task->status = TaskStatus::Reviewing;
            $task->save();
            return NodeResult::done(['skipped' => true, 'testsPassed' => null]);
        }

        $process = Process::path($task->worktree_path)->timeout(600)->run($command);
        $exitCode = $process->exitCode();
        $output = substr($process->output() . $process->errorOutput(), 0, 4000);

        if ($exitCode === 0) {
            $task->status = TaskStatus::Reviewing;
            $task->save();
            return NodeResult::done(['testsPassed' => true, 'log' => $output]);
        }

        $task->status = TaskStatus::Failed;
        
        $memory = app(MemoryStore::class);
        $project = $task->project;
        $taskKey = $task->task_key;
        $originalBrief = $memory->read($project, "tasks/{$taskKey}/task.md") ?? '';
        $newRevision = $task->revision + 1;
        
        $failureBrief = $originalBrief . "\n\n## Test failure (revision {$newRevision})\n\n{$output}\n";
        $memory->write($project, "tasks/{$taskKey}/task.v{$newRevision}.md", $failureBrief);

        $task->revision = $newRevision;
        $task->save();

        if ($newRevision > (int) config('majordom.workflow.max_revisions', 3)) {
            return NodeResult::failed(
                "Tests still failing after {$task->revision} revisions — parked for the owner.",
                ['testsPassed' => false, 'log' => $output],
            );
        }

        $task->update(['status' => TaskStatus::Pending]);

        return NodeResult::retry(
            ['build'],
            "Tests failed — rebuilding with task.v{$newRevision}.md.",
            ['testsPassed' => false, 'log' => $output, 'revision' => $newRevision],
        );
    }
}
