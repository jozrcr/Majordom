<?php

namespace App\Core\Workflow;

use App\Core\Workflow\Nodes\BuildNode;
use App\Core\Workflow\Nodes\CommitSuggestionNode;
use App\Core\Workflow\Nodes\DelegateNode;
use App\Core\Workflow\Nodes\FinalizeNode;
use App\Core\Workflow\Nodes\HumanReviewNode;
use App\Core\Workflow\Nodes\HumanTaskNode;
use App\Core\Workflow\Nodes\ReviewNode;
use App\Core\Workflow\Nodes\TestNode;
use App\Enums\ExecutionStatus;
use App\Models\Project;
use App\Models\Task;
use App\Support\Setting;

/**
 * The one v1 workflow template (SPEC §2): hardcoded, not pluggable
 * (PHILOSOPHY §6). M3 runs the build half for a single task; the consensus
 * and plan phases live in ArchitectService and feed it a task brief.
 */
class ImplementFeatureWorkflow
{
    // M12: the default chain ends in `finalize` — the Builder's work is already
    // committed to the milestone branch during build, so there's no per-task
    // promotion. A project that opts into `confirm_commits` swaps in a
    // `commit_suggestion` human checkpoint instead (see chainFor()).
    public const CHAIN = ['delegate', 'build', 'test', 'review', 'finalize'];

    /** @return array<string, class-string<NodeJob>> */
    public static function nodeMap(): array
    {
        return [
            'delegate' => DelegateNode::class,
            'build' => BuildNode::class,
            'test' => TestNode::class,
            'review' => ReviewNode::class,
            'finalize' => FinalizeNode::class,
            'commit_suggestion' => CommitSuggestionNode::class,
            'human_task' => HumanTaskNode::class,
            'human_review' => HumanReviewNode::class,
        ];
    }

    /**
     * The chain for a project: a custom workflow wins for structure, but the
     * per-project `confirm_commits` toggle is ALWAYS authoritative for the
     * tail checkpoint — `finalize` (hands-off) vs `commit_suggestion`
     * (per-task diff review). Stored chains are normalized in both
     * directions; otherwise a chain seeded before the default changed
     * freezes the old checkpoint and quietly overrides the toggle
     * (a stale seeded chain made "full-auto" runs prompt on every commit).
     *
     * @return array<int, mixed>
     */
    public static function chainFor(Project $project): array
    {
        $chain = $project->workflow?->chain ?: self::CHAIN;
        $checkpoint = $project->confirm_commits ? 'commit_suggestion' : 'finalize';

        return array_map(
            fn ($s) => in_array($s, ['finalize', 'commit_suggestion'], true) ? $checkpoint : $s,
            $chain
        );
    }

    /**
     * Start the build loop for one planned task (its task.md must exist in
     * the project memory).
     */
    public static function startForTask(Project $project, string $taskKey, string $title, string $profile = 'attended'): Task
    {
        $chain = self::chainFor($project);

        $execution = $project->executions()->create([
            'status' => ExecutionStatus::Running,
            'profile' => $profile,
            'spend_cap_usd' => in_array($profile, ['overnight', 'full_auto'], true)
                ? Setting::get('workflow.overnight_spend_cap_usd', config('majordom.workflow.overnight_spend_cap_usd'))
                : null,
            'meta' => ['workflow' => $project->workflow?->name ?? 'Implement Feature'],
        ]);

        // Reuse the task across restarts so its revision (and the v{n}
        // briefs behind it) survive a park — a fresh row would silently
        // rebuild the original brief.
        $task = $project->tasks()->where('task_key', $taskKey)->latest('id')->first()
            ?? $project->tasks()->make(['task_key' => $taskKey, 'title' => $title]);
        $task->fill(['execution_id' => $execution->id, 'status' => \App\Enums\TaskStatus::Pending]);
        $task->title = $task->title ?: $title;
        $task->project_id = $project->id;
        $task->save();

        app(WorkflowEngine::class)->start($execution, $chain);

        return $task;
    }
}
