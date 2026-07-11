<div class="mx-auto max-w-3xl px-7 py-6" @if($runId && (!$run || !in_array($run['phase'] ?? '', ['done', 'error']))) wire:poll.2s @endif>
    <div class="mb-1 flex items-baseline gap-4">
        <h1 class="text-title font-semibold text-hi">Harness smoke test</h1>
        <span class="font-mono text-meta text-mute">dev only · M1 go/no-go</span>
    </div>
    <p class="mb-5 text-body-sm text-t2">Runs one scoped task: coordinator ensures the Builder model in metallama, aider drives it headless in the given repo, the structured result lands below.</p>

    <div class="space-y-3 rounded-xl border border-border-strong bg-surface-card p-4">
        <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">TASK</p>

        <input wire:model="repoPath" type="text" placeholder="/absolute/path/to/scratch-repo"
               class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 font-mono text-body-sm text-hi placeholder:text-faint">
        @error('repoPath') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror

        <textarea wire:model="taskPrompt" rows="3"
                  class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint"></textarea>
        @error('taskPrompt') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror

        <input wire:model="testCommand" type="text" placeholder="test command (optional)"
               class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 font-mono text-body-sm text-hi placeholder:text-faint">

        <div class="flex items-center gap-3">
            <button wire:click="run" wire:loading.attr="disabled"
                    class="rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-semibold text-[#c7d2df] hover:bg-surface-active disabled:opacity-55">
                <span wire:loading.remove wire:target="run">Run task</span>
                <span wire:loading wire:target="run">Dispatching…</span>
            </button>
            <span class="font-mono text-meta text-faint">needs a queue worker on the harness queue</span>
        </div>
    </div>

    @if ($runId)
        <div class="mt-5 rounded-xl border border-border bg-surface-card p-4">
            <div class="flex items-center gap-2.5">
                @php $phase = $run['phase'] ?? 'unknown'; @endphp
                @if (in_array($phase, ['queued', 'ensuring model', 'building']))
                    <span class="h-2 w-2 rounded-full bg-status-working animate-led-pulse"></span>
                @elseif ($phase === 'error')
                    <span class="h-2 w-2 rounded-full bg-status-failed"></span>
                @else
                    <span class="h-2 w-2 rounded-full bg-ok"></span>
                @endif
                <span class="font-mono text-meta text-text">{{ $phase }}</span>
                <span class="ml-auto font-mono text-meta text-faint">{{ $run['updated_at'] ?? '' }}</span>
            </div>

            @if ($phase === 'error')
                <pre class="mt-3 overflow-x-auto rounded-lg bg-failed-tint p-3 font-mono text-caption text-failed-text">{{ $run['payload']['message'] ?? 'unknown' }}</pre>
            @endif

            @if ($phase === 'done' && is_array($run['payload'] ?? null))
                @php $r = $run['payload']; @endphp
                <div class="mt-3 space-y-2">
                    <p class="font-mono text-meta">
                        <span class="{{ $r['status'] === 'completed' ? 'text-ok' : 'text-failed-text' }}">status: {{ $r['status'] }}</span>
                        <span class="text-mute"> · tests: {{ is_null($r['testsPassed'] ?? null) ? 'not run' : (($r['testsPassed']) ? 'passed' : 'failed') }}</span>
                        <span class="text-mute"> · {{ count($r['filesChanged'] ?? []) }} file(s)</span>
                    </p>
                    <p class="text-body-sm text-t2">{{ $r['summary'] ?? '' }}</p>
                    @if (!empty($r['diff']))
                        <pre class="max-h-[480px] overflow-auto rounded-lg border border-border-soft bg-surface p-3 font-mono text-[12px] leading-[1.75] text-t3">{{ $r['diff'] }}</pre>
                    @endif
                    @if (!empty($r['rawLog']))
                        <details class="text-body-sm text-t3">
                            <summary class="cursor-pointer font-mono text-meta text-mute">raw harness log</summary>
                            <pre class="mt-2 max-h-[320px] overflow-auto rounded-lg border border-border-soft bg-surface p-3 font-mono text-[11px] text-mute">{{ $r['rawLog'] }}</pre>
                        </details>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
