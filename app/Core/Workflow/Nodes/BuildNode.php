<?php

namespace App\Core\Workflow\Nodes;

use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessStatus;
use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Runtime\Metallama\ResourceCoordinator;

class BuildNode extends NodeJob
{
    public function __construct(protected int $nodeId)
    {
    }

    protected function run(Node $node, Execution $execution): NodeResult
    {
        /** @var Task|null $task */
        $task = $execution->tasks()->first();
        if (!$task) {
            return NodeResult::failed('Execution has no task.');
        }

        if (!$task->worktree_path) {
            return NodeResult::failed('Task has no worktree.');
        }

        app(ResourceCoordinator::class)->ensure(config('majordom.builder.model'));

        $memory = app(MemoryStore::class);
        $project = $task->project;
        $taskKey = $task->task_key;

        $rolePrompt = $memory->read($project, "tasks/{$taskKey}/role.md") ?? '';
        
        $taskBriefPath = "tasks/{$taskKey}/task.md";
        if ($task->revision > 1) {
            $revisionPath = "tasks/{$taskKey}/task.v{$task->revision}.md";
            if ($memory->exists($project, $revisionPath)) {
                $taskBriefPath = $revisionPath;
            }
        }
        $taskPrompt = $memory->read($project, $taskBriefPath) ?? '';

        $result = app(Harness::class)->runTask(new HarnessRequest(
            repoPath: $task->worktree_path,
            endpointBaseUrl: config('metallama.base_url') . '/ollama/v1',
            modelName: config('majordom.builder.gateway_model'),
            rolePrompt: $rolePrompt,
            taskPrompt: $taskPrompt,
            testCommand: $project->test_command,
        ));

        $handoff = "# Build Handoff\n\n";
        $handoff .= "## Summary\n{$result->summary}\n\n";
        $handoff .= "## Files Changed\n";
        foreach ($result->filesChanged as $file) {
            $handoff .= "- {$file}\n";
        }
        $handoff .= "\n## Tests Passed\n".($result->testsPassed ? 'Yes' : 'No')."\n\n";
        $handoff .= "## Open Questions\n";
        foreach ($result->openQuestions as $q) {
            $handoff .= "- {$q}\n";
        }
        $handoff .= "\n## Raw log (truncated)\n".substr($result->rawLog, 0, 2000)."\n";

        $memory->write($project, "tasks/{$taskKey}/handoff.md", $handoff);

        if ($result->status !== HarnessStatus::Completed) {
            $task->status = TaskStatus::Failed;
            $task->save();
            return NodeResult::failed('Build failed: '.$result->summary, $result->toArray());
        }

        $task->status = TaskStatus::Testing;
        $task->save();

        return NodeResult::done($result->toArray());
    }
}
