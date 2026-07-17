@php
    $pipeline = $this->pipeline;
    $blocker = $pipeline['blocker'];
    $nodes = $pipeline['nodes'];
@endphp

<div class="border-b border-border px-4 py-3 bg-surface-card">
    {{-- Blocker headline --}}
    <div class="flex items-center gap-2 text-sm font-medium text-hi mb-3">
        @php
            $dotClass = 'bg-status-idle';
            if (str_starts_with($blocker, 'Waiting')) $dotClass = 'bg-accent';
            elseif (str_starts_with($blocker, 'Working')) $dotClass = 'bg-status-working animate-pulse';
            elseif (str_starts_with($blocker, 'Parked')) $dotClass = 'bg-status-working';
            elseif (str_starts_with($blocker, 'Failed')) $dotClass = 'bg-status-failed';
        @endphp
        <span class="h-2 w-2 rounded-full {{ $dotClass }}"></span>
        <span>{{ $blocker }}</span>
    </div>

    {{-- Pipeline strip --}}
    @if(!empty($nodes))
        <div class="flex flex-wrap items-center gap-2">
            @foreach($nodes as $node)
                @php
                    $status = $node['status'];
                    $chipClass = 'rounded-lg border px-2.5 py-1 text-xs font-mono flex items-center gap-1.5';
                    $statusClass = 'bg-status-idle border-border-soft text-mute';
                    $prefix = '';

                    if ($status === 'completed') {
                        $statusClass = 'bg-surface-active border-ok/40 text-ok';
                        $prefix = '✓';
                    } elseif ($status === 'running') {
                        $statusClass = 'bg-working-tint border-status-working text-status-working animate-pulse';
                        $prefix = '●';
                    } elseif ($status === 'failed') {
                        $statusClass = 'bg-failed-tint border-failed-border text-failed-text';
                        $prefix = '✗';
                    } elseif ($status === 'waiting_human') {
                        $statusClass = 'bg-accent-tint border-accent text-accent';
                        $prefix = '⏸';
                    }
                @endphp
                <button
                    type="button"
                    wire:click="inspectNode({{ $node['id'] }})"
                    class="{{ $chipClass }} {{ $statusClass }} {{ $node['id'] === $inspectedNodeId ? 'ring-2 ring-accent ring-offset-1 ring-offset-surface-card' : '' }}"
                >
                    <span>{{ $prefix }}</span>
                    <span>{{ $node['label'] }}</span>
                </button>
            @endforeach
        </div>
    @endif
</div>
