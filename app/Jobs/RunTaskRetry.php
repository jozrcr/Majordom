<?php

namespace App\Jobs;

use App\Agents\Architect\ArchitectService;
use App\Core\Events\EventRecorder;
use App\Core\Workflow\BuilderSelector;
use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Enums\ImplementationStrategy;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Recovery for a parked/failed task (M14b): regenerate a FRESH brief from the
 * current roadmap (escaping the poisoned brief that caused the failure loop),
 * optionally escalate the Builder to the frontier model, then relaunch the
 * build. Off-request because the brief regeneration makes a provider call.
 *
 * This is the backend for the escalation menu's "Retry" / "Select stronger
 * Builder" actions and the direct unstick for TEST-M12bis (T-014 failed 5×).
 */
class RunTaskRetry implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $projectId,
        public string $taskKey,
        public bool $escalateToFrontier = false,
        public string $profile = 'attended',
    ) {}

    public function handle(ArchitectService $architect): void
    {
        Cache::put("architect-turn:{$this->projectId}", 'planning', now()->addMinutes(15));

        try {
            $project = Project::findOrFail($this->projectId);
            $task = $project->tasks()->where('task_key', $this->taskKey)->latest('id')->first();
            if ($task === null) {
                return;
            }

            // Escalate the Builder first so the fresh brief and the relaunch both
            // reflect the chosen (frontier) Builder.
            if ($this->escalateToFrontier) {
                app(BuilderSelector::class)->assign($task, ImplementationStrategy::Frontier, 'you');
            }

            // Fresh brief from the current roadmap — the core of the recovery:
            // the failure was a poisoned/stale brief, so rebuild it clean.
            $architect->refreshTaskBrief($project, $this->taskKey);

            app(EventRecorder::class)->record(
                $project,
                'task.retried',
                ['task_key' => $this->taskKey, 'escalated' => $this->escalateToFrontier],
                null,
                'you'
            );

            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => "Retrying {$this->taskKey} with a fresh brief"
                    .($this->escalateToFrontier ? ' on the frontier Builder' : '').'.',
                'meta' => ['task_retried' => $this->taskKey],
            ]);

            $profile = in_array($this->profile, ['attended', 'overnight', 'full_auto'], true) ? $this->profile : 'attended';
            ImplementFeatureWorkflow::startForTask($project, $this->taskKey, $task->title, $profile);
        } finally {
            Cache::forget("architect-turn:{$this->projectId}");
        }
    }

    public function failed(?\Throwable $e): void
    {
        Cache::forget("architect-turn:{$this->projectId}");

        $project = Project::find($this->projectId);
        $project?->consensusMessages()->create([
            'role' => MessageRole::System,
            'content' => "Retry of {$this->taskKey} failed: ".($e?->getMessage() ?? 'unknown failure'),
        ]);
        $project?->update(['status' => ProjectStatus::Idle, 'last_activity_at' => now()]);
    }
}
