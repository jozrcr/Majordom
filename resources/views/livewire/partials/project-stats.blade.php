<div class="flex h-full flex-col gap-6 overflow-y-auto p-6">
    @php
        $stats = $this->usageStats;
        $execCounts = $this->executionCounts;
        $hasData = $stats['by_role']->isNotEmpty() || !empty($execCounts);
    @endphp

    @if(!$hasData)
        <div class="flex flex-1 items-center justify-center">
            <p class="text-body text-mute">No usage or execution data recorded yet.</p>
        </div>
    @else
        <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
            <h2 class="text-lg font-semibold text-hi">Usage Totals</h2>
            <div class="overflow-x-auto">
                <table class="w-full font-mono text-sm">
                    <thead>
                        <tr class="border-b border-border-soft text-left text-mute">
                            <th class="pb-2">Role</th>
                            <th class="pb-2">Prompt Tokens</th>
                            <th class="pb-2">Completion Tokens</th>
                            <th class="pb-2">Cost (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats['by_role'] as $row)
                            <tr class="border-b border-border-soft last:border-0">
                                <td class="py-2 text-hi">{{ $row->role }}</td>
                                <td class="py-2 text-t3">{{ number_format($row->prompt_tokens ?? 0) }}</td>
                                <td class="py-2 text-t3">{{ number_format($row->completion_tokens ?? 0) }}</td>
                                <td class="py-2 text-t3">${{ number_format($row->cost_usd ?? 0, 4) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="py-2 text-hi">Total</td>
                            <td class="py-2 text-hi">{{ number_format($stats['total']->prompt_tokens ?? 0) }}</td>
                            <td class="py-2 text-hi">{{ number_format($stats['total']->completion_tokens ?? 0) }}</td>
                            <td class="py-2 text-hi">${{ number_format($stats['total']->cost_usd ?? 0, 4) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
            <h2 class="text-lg font-semibold text-hi">Executions by Status</h2>
            <div class="grid grid-cols-2 gap-4 font-mono text-sm">
                @foreach($execCounts as $status => $count)
                    <div class="flex items-center justify-between rounded bg-surface p-2">
                        <span class="text-mute">{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
                        <span class="font-semibold text-hi">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
            <h2 class="text-lg font-semibold text-hi">Per-milestone metrics</h2>
            @if(empty($this->milestoneMetrics))
                <p class="text-body text-mute">No milestones yet.</p>
            @else
                <div class="space-y-4" x-data="{ open: {} }">
                    @foreach($this->milestoneMetrics as $mi)
                        <div class="border border-border-soft rounded-lg">
                            <button
                                type="button"
                                @click="open['{{ $mi['key'] }}'] = !open['{{ $mi['key'] }}']"
                                class="w-full flex items-center justify-between p-3 text-left hover:bg-surface cursor-pointer"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full"
                                          :class="{
                                              'bg-green-500': '{{ $mi['status'] }}' === 'done',
                                              'bg-yellow-500': '{{ $mi['status'] }}' === 'ongoing',
                                              'bg-gray-400': '{{ $mi['status'] }}' === 'todo'
                                          }"></span>
                                    <span class="font-mono text-sm font-semibold text-hi">{{ $mi['key'] }}</span>
                                    <span class="text-sm text-t3">{{ $mi['title'] }}</span>
                                </div>
                                <span class="text-xs text-mute" x-text="open['{{ $mi['key'] }}'] ? '▲' : '▼'"></span>
                            </button>

                            <div x-show="open['{{ $mi['key'] }}']" class="p-3 border-t border-border-soft bg-surface/50">
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 text-xs font-mono mb-3">
                                    <div class="text-mute">Tokens (A/B/R)<br><span class="text-hi">{{ number_format($mi['metrics']['tokens']['architect']) }} / {{ number_format($mi['metrics']['tokens']['builder']) }} / {{ number_format($mi['metrics']['tokens']['reviewer']) }}</span></div>
                                    <div class="text-mute">Cost<br><span class="text-hi">${{ number_format($mi['metrics']['cost_usd'], 4) }}</span></div>
                                    <div class="text-mute">Human<br><span class="text-hi">{{ $mi['metrics']['human_interventions'] }}</span></div>
                                    <div class="text-mute">Rework<br><span class="text-hi">{{ $mi['metrics']['rework_cycles'] }}</span></div>
                                    <div class="text-mute">Files<br><span class="text-hi">{{ $mi['metrics']['files_changed'] }}</span></div>
                                    <div class="text-mute">Time<br><span class="text-hi">{{ $mi['metrics']['time_to_completion'] !== null ? floor($mi['metrics']['time_to_completion'] / 60) . 'm ' . ($mi['metrics']['time_to_completion'] % 60) . 's' : '—' }}</span></div>
                                </div>

                                @if(!empty($mi['tasks']))
                                    <table class="w-full text-xs font-mono">
                                        <thead>
                                            <tr class="text-left text-mute border-b border-border-soft">
                                                <th class="pb-1">Task</th>
                                                <th class="pb-1">Tokens (A/B/R)</th>
                                                <th class="pb-1">Cost</th>
                                                <th class="pb-1">Human</th>
                                                <th class="pb-1">Rework</th>
                                                <th class="pb-1">Files</th>
                                                <th class="pb-1">Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($mi['tasks'] as $ti)
                                                <tr class="border-b border-border-soft last:border-0">
                                                    <td class="py-1 text-hi">{{ $ti['key'] }} {{ $ti['title'] }}</td>
                                                    <td class="py-1 text-t3">{{ number_format($ti['metrics']['tokens']['architect']) }} / {{ number_format($ti['metrics']['tokens']['builder']) }} / {{ number_format($ti['metrics']['tokens']['reviewer']) }}</td>
                                                    <td class="py-1 text-t3">${{ number_format($ti['metrics']['cost_usd'], 4) }}</td>
                                                    <td class="py-1 text-t3">{{ $ti['metrics']['human_interventions'] }}</td>
                                                    <td class="py-1 text-t3">{{ $ti['metrics']['rework_cycles'] }}</td>
                                                    <td class="py-1 text-t3">{{ $ti['metrics']['files_changed'] }}</td>
                                                    <td class="py-1 text-t3">{{ $ti['metrics']['time_to_completion'] !== null ? floor($ti['metrics']['time_to_completion'] / 60) . 'm ' . ($ti['metrics']['time_to_completion'] % 60) . 's' : '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
