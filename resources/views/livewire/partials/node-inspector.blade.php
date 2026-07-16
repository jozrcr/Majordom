@php $inspectedNode = $this->inspectedNode; @endphp
@if($inspectedNode)
<div class="border-b border-border bg-surface-card px-4 py-3 space-y-3">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="font-mono text-sm font-semibold text-hi">{{ $inspectedNode->type }}</span>
            @php
                $status = $inspectedNode->status->value;
                $statusClass = 'bg-status-idle border-border-soft text-mute';
                if ($status === 'completed') $statusClass = 'bg-ok border-ok text-ok';
                elseif ($status === 'running') $statusClass = 'bg-status-working border-status-working text-status-working animate-pulse';
                elseif ($status === 'failed') $statusClass = 'bg-failed-tint border-failed-border text-failed-text';
                elseif ($status === 'waiting_human') $statusClass = 'bg-accent-tint border-accent text-accent';
            @endphp
            <span class="rounded-lg border px-2 py-0.5 text-[10px] font-mono {{ $statusClass }}">{{ $inspectedNode->status->label() }}</span>
            @php
                $duration = '—';
                if ($inspectedNode->started_at && $inspectedNode->finished_at) {
                    $diff = $inspectedNode->started_at->diffInSeconds($inspectedNode->finished_at);
                    $mins = intdiv($diff, 60);
                    $secs = $diff % 60;
                    $duration = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
                } elseif ($inspectedNode->started_at) {
                    $duration = 'running…';
                }
            @endphp
            <span class="font-mono text-meta text-mute">{{ $duration }}</span>
        </div>
        <button type="button" wire:click="inspectNode({{ $inspectedNode->id }})" class="text-mute hover:text-hi transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>

    @if($inspectedNode->status === \App\Enums\NodeStatus::Failed)
        @php
            $errorDetail = $inspectedNode->output['error'] ?? $inspectedNode->output['message'] ?? 'no detail recorded';
        @endphp
        <div class="rounded-md border border-failed-border bg-failed-tint p-3">
            <p class="font-mono text-sm text-failed-text">{{ $errorDetail }}</p>
        </div>
    @endif

    @if(!empty($inspectedNode->input))
        <div>
            <p class="font-mono text-micro uppercase tracking-[.14em] text-mute mb-1">Input</p>
            @php
                $inputJson = json_encode($inspectedNode->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $inputJson = mb_strlen($inputJson) > 2000 ? mb_substr($inputJson, 0, 2000) . '… (truncated)' : $inputJson;
            @endphp
            <pre class="max-h-[240px] overflow-auto rounded-md border border-border-soft bg-surface p-3 font-mono text-[11px] leading-relaxed text-t3">{{ $inputJson }}</pre>
        </div>
    @endif

    @if(!empty($inspectedNode->output))
        <div>
            <p class="font-mono text-micro uppercase tracking-[.14em] text-mute mb-1">Output</p>
            @php
                $outputJson = json_encode($inspectedNode->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $outputJson = mb_strlen($outputJson) > 2000 ? mb_substr($outputJson, 0, 2000) . '… (truncated)' : $outputJson;
            @endphp
            <pre class="max-h-[240px] overflow-auto rounded-md border border-border-soft bg-surface p-3 font-mono text-[11px] leading-relaxed text-t3">{{ $outputJson }}</pre>
        </div>
    @endif
</div>
@endif
