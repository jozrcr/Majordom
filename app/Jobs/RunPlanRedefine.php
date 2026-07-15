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
 * Post-plan "Redefine milestones / specs": the Architect revises the roadmap
 * from the owner's instruction and re-syncs (one provider turn), off-request.
 */
class RunPlanRedefine implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $projectId, public string $instruction) {}

    public function handle(ArchitectService $architect): void
    {
        Cache::put("architect-turn:{$this->projectId}", 'planning', now()->addMinutes(15));

        try {
            $project = Project::findOrFail($this->projectId);
            $architect->redefinePlan($project, $this->instruction);
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
            'content' => 'Roadmap revision failed: '.($e?->getMessage() ?? 'unknown failure'),
        ]);
        $project?->update(['status' => ProjectStatus::Idle, 'last_activity_at' => now()]);
    }
}
