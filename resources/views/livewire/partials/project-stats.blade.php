<div class="flex h-full flex-col gap-6 overflow-y-auto p-6">
    @php
        $stats = $usageStats;
        $execCounts = $executionCounts;
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
    @endif
</div>
