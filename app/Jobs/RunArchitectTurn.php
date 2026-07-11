<?php

namespace App\Jobs;

use App\Agents\Architect\ArchitectService;
use App\Enums\MessageRole;
use App\Models\ConsensusMessage;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RunArchitectTurn implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public int $projectId,
        public ?string $userMessage = null,
    ) {}

    public function handle(ArchitectService $architect): void
    {
        Cache::put("architect-turn:{$this->projectId}", 'thinking', now()->addMinutes(15));

        try {
            $project = Project::findOrFail($this->projectId);
            $architect->converse($project, $this->userMessage);
        } catch (\Throwable $e) {
            $project = Project::findOrFail($this->projectId);
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => 'Architect turn failed: '.$e->getMessage(),
                'meta' => null,
            ]);
            throw $e;
        } finally {
            Cache::forget("architect-turn:{$this->projectId}");
        }
    }

    /**
     * Also fires when the job dies BEFORE handle() (e.g. a stale worker that
     * cannot resolve dependencies) — the UI must never stay on "thinking".
     */
    public function failed(?\Throwable $e): void
    {
        Cache::forget("architect-turn:{$this->projectId}");

        Project::find($this->projectId)?->consensusMessages()->create([
            'role' => MessageRole::System,
            'content' => 'Architect turn failed: '.($e?->getMessage() ?? 'unknown failure'),
        ]);
    }
}
