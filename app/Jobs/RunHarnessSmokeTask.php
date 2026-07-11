<?php

namespace App\Jobs;

use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Runtime\Metallama\ResourceCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dev-only M1 smoke job: ensure the Builder's model is up in metallama, run
 * one scoped task through the Harness, park the HarnessResult in cache for
 * the HarnessSmoke page to poll. Replaced by real workflow Nodes in M3.
 */
class RunHarnessSmokeTask implements ShouldQueue
{
    use Queueable;

    // Build runs can take tens of minutes; single attempt, no retry
    // (a re-dispatched harness mid-run would double-edit the repo).
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $repoPath,
        public string $taskPrompt,
        public ?string $testCommand = null,
    ) {}

    public function handle(ResourceCoordinator $coordinator, Harness $harness): void
    {
        try {
            $this->put('ensuring model', null);

            $coordinator->ensure((string) config('majordom.builder.model'));

            $this->put('building', null);

            $result = $harness->runTask(new HarnessRequest(
                repoPath: $this->repoPath,
                endpointBaseUrl: rtrim((string) config('majordom.metallama.base_url'), '/').'/ollama/v1',
                modelName: (string) config('majordom.builder.gateway_model'),
                rolePrompt: "You are a careful software engineer. Make the smallest change that satisfies the task. Do not refactor unrelated code.",
                taskPrompt: $this->taskPrompt,
                testCommand: $this->testCommand,
            ));

            $this->put('done', $result->toArray());
        } catch (\Throwable $e) {
            $this->put('error', ['message' => $e->getMessage(), 'class' => $e::class]);

            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        $this->put('error', ['message' => $e?->getMessage() ?? 'unknown failure']);
    }

    private function put(string $phase, ?array $payload): void
    {
        Cache::put("harness-smoke:{$this->runId}", [
            'phase' => $phase,
            'payload' => $payload,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }
}
