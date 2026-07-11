<?php

namespace App\Livewire;

use App\Jobs\RunHarnessSmokeTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dev-only M1 de-risk page: run one trivial task through
 * coordinator -> metallama -> aider -> HarnessResult and show the diff.
 */
#[Title('Majordom — Harness smoke test')]
class HarnessSmoke extends Component
{
    public string $repoPath = '';

    public string $taskPrompt = 'Add a concise docstring/comment to the main entry point explaining what it does.';

    public ?string $testCommand = null;

    public ?string $runId = null;

    public function run(): void
    {
        $this->validate([
            'repoPath' => 'required|string',
            'taskPrompt' => 'required|string|max:4000',
        ]);

        if (! is_dir($this->repoPath) || ! file_exists(rtrim($this->repoPath, '/').'/.git')) {
            $this->addError('repoPath', 'Not a git repository.');

            return;
        }

        $this->runId = Str::uuid()->toString();

        Cache::put("harness-smoke:{$this->runId}", [
            'phase' => 'queued',
            'payload' => null,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        RunHarnessSmokeTask::dispatch(
            $this->runId,
            $this->repoPath,
            $this->taskPrompt,
            $this->testCommand ?: null,
        )->onQueue('harness');
    }

    public function render()
    {
        $run = $this->runId
            ? Cache::get("harness-smoke:{$this->runId}")
            : null;

        return view('livewire.harness-smoke', ['run' => $run]);
    }
}
