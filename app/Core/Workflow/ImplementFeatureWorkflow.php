<?php

namespace App\Core\Workflow;

use App\Core\Workflow\Nodes\BuildNode;
use App\Core\Workflow\Nodes\CommitSuggestionNode;
use App\Core\Workflow\Nodes\DelegateNode;
use App\Core\Workflow\Nodes\ReviewNode;
use App\Core\Workflow\Nodes\TestNode;
use App\Enums\ExecutionStatus;
use App\Models\Project;
use App\Models\Task;

/**
 * The one v1 workflow template (SPEC §2): hardcoded, not pluggable
 * (PHILOSOPHY §6). M3 runs the build half for a single task; the consensus
 * and plan phases live in ArchitectService and feed it a task brief.
 */
class ImplementFeatureWorkflow
{
    public const CHAIN = ['delegate', 'build', 'test', 'review', 'commit_suggestion'];

    /** @return array<string, class-string<NodeJob>> */
    public static function nodeMap(): array
    {
        return [
            'delegate' => DelegateNode::class,
            'build' => BuildNode::class,
            'test' => TestNode::class,
            'review' => ReviewNode::class,
            'commit_suggestion' => CommitSuggestionNode::class,
        ];
    }

    /**
     * Start the build loop for one planned task (its task.md must exist in
     * the project memory).
     */
    public static function startForTask(Project $project, string $taskKey, string $title): Task
    {
        $execution = $project->executions()->create(['status' => ExecutionStatus::Running]);

        // Reuse the task across restarts so its revision (and the v{n}
        // briefs behind it) survive a park — a fresh row would silently
        // rebuild the original brief.
        $task = $project->tasks()->where('task_key', $taskKey)->latest('id')->first()
            ?? $project->tasks()->make(['task_key' => $taskKey, 'title' => $title]);
        $task->fill(['execution_id' => $execution->id, 'status' => \App\Enums\TaskStatus::Pending]);
        $task->title = $task->title ?: $title;
        $task->project_id = $project->id;
        $task->save();

        app(WorkflowEngine::class)->start($execution, self::CHAIN);

        return $task;
    }
}
