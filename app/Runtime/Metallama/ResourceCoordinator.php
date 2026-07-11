<?php

namespace App\Runtime\Metallama;

use App\Runtime\Metallama\Exceptions\CoordinatorTimeout;

class ResourceCoordinator
{
    public function __construct(
        private readonly MetallamaClient $client,
        private ?\Closure $sleeper = null,
    ) {
        $this->sleeper ??= fn (int $ms) => usleep($ms * 1000);
    }

    public function ensure(string $modelId): ModelState
    {
        $startTimeout = (int) config('majordom.metallama.start_timeout', 300);
        $stopTimeout = (int) config('majordom.metallama.stop_timeout', 60);
        $pollIntervalMs = (int) config('majordom.metallama.poll_interval_ms', 2000);

        $states = $this->client->models();
        $requiredState = null;
        foreach ($states as $state) {
            if ($state->id === $modelId) {
                $requiredState = $state;
                break;
            }
        }

        if ($requiredState !== null && $requiredState->isOnline()) {
            return $requiredState;
        }

        if ($requiredState !== null && $requiredState->status->value === 'Starting') {
            return $this->waitUntilOnline($modelId, $startTimeout, $pollIntervalMs, 'starting');
        }

        foreach ($states as $state) {
            if ($state->id === $modelId) {
                continue;
            }
            if ($state->isOnline() || $state->status->value === 'Starting') {
                $this->client->stop($state->id);
                $this->waitUntilOffline($state->id, $stopTimeout, $pollIntervalMs);
            }
        }

        $this->client->start($modelId);
        return $this->waitUntilOnline($modelId, $startTimeout, $pollIntervalMs, 'starting');
    }

    private function waitUntilOffline(string $id, int $timeout, int $pollIntervalMs): void
    {
        $deadline = microtime(true) + $timeout;
        $lastState = null;
        while (microtime(true) < $deadline) {
            ($this->sleeper)($pollIntervalMs);
            $lastState = $this->client->status($id);
            if ($lastState->status->value === 'Offline') {
                return;
            }
        }
        throw new CoordinatorTimeout("timed out stopping {$id}", $lastState);
    }

    private function waitUntilOnline(string $id, int $timeout, int $pollIntervalMs, string $phase): ModelState
    {
        $deadline = microtime(true) + $timeout;
        $wasStarting = false;
        $lastState = null;

        while (microtime(true) < $deadline) {
            ($this->sleeper)($pollIntervalMs);
            $lastState = $this->client->status($id);

            if ($lastState->isOnline()) {
                return $lastState;
            }

            if ($lastState->status->value === 'Starting') {
                $wasStarting = true;
            } elseif ($lastState->status->value === 'Offline' && $wasStarting) {
                $msg = "model {$id} exited while starting";
                if ($lastState->lastExit) $msg .= " (exit: {$lastState->lastExit})";
                if ($lastState->lastLog) $msg .= " (log: {$lastState->lastLog})";
                throw new CoordinatorTimeout($msg, $lastState);
            }
        }

        throw new CoordinatorTimeout("timed out {$phase} {$id}", $lastState);
    }
}
