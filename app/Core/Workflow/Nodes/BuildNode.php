<?php

namespace App\Core\Workflow\Nodes;

use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessStatus;
use App\Core\Usage\UsageLedger;
use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\ProviderEndpoint;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Support\RoleResolver;

class BuildNode extends NodeJob
{
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

        $roleName = $node->input['role'] ?? 'builder';
        $binding = app(RoleResolver::class)->resolve($roleName, $task->project);

        $endpoint = ProviderEndpoint::named($binding->provider);
        if (! $endpoint) {
            return NodeResult::failed("Unknown provider endpoint: {$binding->provider}");
        }

        if ($endpoint->driver === 'metallama') {
            $managedModel = $binding->meta['managed_model'] ?? $binding->model;
            app(ResourceCoordinator::class)->ensure($managedModel);
        }

        $endpointBaseUrl = $endpoint->chatBaseUrl();
        $apiKey = $endpoint->resolvedApiKey();

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

        $extraInstructions = $binding->meta['extra_instructions'] ?? null;
        if ($extraInstructions !== null && trim($extraInstructions) !== '') {
            $taskPrompt .= "\n\n## Owner role instructions\n\n" . trim($extraInstructions);
        }

        // Collect fileHints from latest review node
        $fileHints = [];
        $latestReview = $execution->nodes()
            ->where('type', 'review')
            ->whereIn('status', [NodeStatus::Completed, NodeStatus::Failed])
            ->orderByDesc('id')
            ->first();
            
        if ($latestReview && isset($latestReview->output['verdict']['comments'])) {
            foreach ($latestReview->output['verdict']['comments'] as $comment) {
                if (!empty($comment['file'])) {
                    $fileHints[] = $comment['file'];
                }
            }
        }

        $result = app(Harness::class)->runTask(new HarnessRequest(
            repoPath: $task->worktree_path,
            endpointBaseUrl: $endpointBaseUrl,
            modelName: $binding->model,
            rolePrompt: $rolePrompt,
            taskPrompt: $taskPrompt,
            testCommand: $project->test_command,
            fileHints: $fileHints,
            apiKey: $apiKey,
        ));

        [$sent, $received] = UsageLedger::parseAiderTokens($result->rawLog);
        if ($sent + $received > 0) {
            app(UsageLedger::class)->record(
                $project,
                $roleName,
                $binding->model,
                $sent,
                $received,
                $execution
            );
        }

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
