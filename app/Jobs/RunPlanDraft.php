<?php

namespace App\Jobs;

use App\Agents\Architect\ArchitectService;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Runs after the owner explicitly approves the plan (the SPEC §3 phase-2
 * gate): the Architect distills the consensus into project memory files.
 */
class RunPlanDraft implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $projectId) {}

    public function handle(ArchitectService $architect): void
    {
        Cache::put("architect-turn:{$this->projectId}", 'planning', now()->addMinutes(15));

        try {
            $project = Project::findOrFail($this->projectId);
            $architect->approvePlan($project);
            $project->update(['status' => ProjectStatus::Idle, 'last_activity_at' => now()]);
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
            'content' => 'Plan drafting failed: '.($e?->getMessage() ?? 'unknown failure'),
        ]);
        $project?->update(['status' => ProjectStatus::Parked, 'last_activity_at' => now()]);
    }
}
