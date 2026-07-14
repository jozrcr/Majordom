<div class="flex h-full flex-col">
    @if($this->executions->count() > 1)
        <div class="border-b border-border px-4 py-3">
            <label class="font-mono text-meta text-mute mr-2">execution</label>
            <select wire:model.live="selectedExecutionId" class="rounded border border-border bg-surface px-2 py-1 text-xs font-mono text-hi">
                @foreach($this->executions as $exec)
                    <option value="{{ $exec->id }}">#{{ $exec->id }} · {{ $exec->created_at->format('M d H:i') }} · {{ $exec->status->value }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @php $data = $this->exchanges; @endphp
    @if($data['execution'])
        <div class="border-b border-border px-4 py-3">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="font-mono text-micro uppercase tracking-[.14em] text-mute">execution #{{ $data['execution']->id }}</span>
                <span class="rounded-[5px] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em] {{ $data['execution']->status->value === 'completed' ? 'bg-ok/20 text-ok' : ($data['execution']->status->value === 'failed' ? 'bg-status-failed/20 text-failed-text' : 'bg-status-working/20 text-accent') }}">
                    {{ $data['execution']->status->value }}
                </span>
            </div>
            @if(!empty($data['usage']))
                <div class="mt-2 flex flex-wrap gap-3 font-mono text-meta text-mute">
                    @foreach($data['usage'] as $role => $stats)
                        <span>{{ $role }}: {{ number_format($stats['prompt_tokens']) }} in · {{ number_format($stats['completion_tokens']) }} out · ${{ number_format($stats['cost_usd'], 4) }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="flex-1 overflow-y-auto p-4 space-y-3">
        @forelse($data['rows'] as $row)
            <div class="rounded-lg border border-border bg-surface-card p-3 space-y-2">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="rounded-[4px] bg-surface-active px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[.08em] text-t3">{{ $row['from'] }}</span>
                    <span class="text-faint">→</span>
                    <span class="rounded-[4px] bg-surface-active px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[.08em] text-t3">{{ $row['to'] }}</span>
                    <span class="rounded-[4px] border border-border-soft px-1.5 py-0.5 text-[10px] font-mono uppercase tracking-[.08em] text-mute">{{ $row['kind'] }}</span>
                    <span class="ml-auto font-mono text-meta text-faint">{{ $row['at']->diffForHumans() }}</span>
                </div>
                <div x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="w-full text-left cursor-pointer group">
                        <p class="text-body-sm text-t2 group-hover:text-hi transition-colors">{{ $row['excerpt'] }}</p>
                    </button>
                    <div x-show="open" x-cloak class="mt-2">
                        <pre class="max-h-[240px] overflow-auto rounded-md border border-border-soft bg-bg p-2 font-mono text-[11px] leading-relaxed text-t3 whitespace-pre-wrap">{{ $row['full'] }}</pre>
                    </div>
                </div>
            </div>
        @empty
            @if($data['execution'])
                <div class="py-12 text-center">
                    <p class="font-mono text-meta text-faint">no exchange events recorded for this execution</p>
                </div>
            @else
                <div class="py-12 text-center">
                    <p class="font-mono text-meta text-faint">no executions yet</p>
                </div>
            @endif
        @endforelse
    </div>
</div>
