<div class="flex h-full flex-col gap-6 overflow-y-auto p-6">
    {{-- Agreed Plan Accordion --}}
    <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
        <h2 class="text-lg font-semibold text-hi">Agreed Plan</h2>
        @if($this->planText || $this->plannedTask)
            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left text-body-sm text-text hover:text-hi cursor-pointer">
                    <span>
                        @if($this->plannedTask)
                            First task: <span class="font-mono">{{ $this->plannedTask['key'] }}</span> — {{ $this->plannedTask['title'] }}
                        @else
                            View the agreed plan
                        @endif
                    </span>
                    <span class="transition-transform duration-120" :class="open && 'rotate-180'">▼</span>
                </button>
                <div x-show="open" x-cloak class="mt-2">
                    @if($this->planText)
                        <pre class="whitespace-pre-wrap font-mono text-xs text-t2 leading-relaxed">{{ $this->planText }}</pre>
                    @else
                        <p class="text-body-sm text-t2">Plan approved. Builder will execute tasks sequentially.</p>
                    @endif
                </div>
            </div>
        @else
            <p class="text-body-sm text-mute">No approved plan yet. Consensus is needed to generate the project memory and task briefs.</p>
        @endif
    </div>

    {{-- Milestones --}}
    @forelse($this->roadmap as $milestone)
        <div class="rounded-lg border border-border bg-surface-card overflow-hidden">
            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-active transition-colors">
                    <span class="h-2.5 w-2.5 rounded-full {{ $milestone['status'] === 'done' ? 'bg-ok' : ($milestone['status'] === 'ongoing' ? 'bg-status-working' : 'bg-status-idle') }}"></span>
                    <span class="font-mono text-sm font-semibold text-hi">{{ $milestone['key'] }}</span>
                    <span class="text-sm text-text">{{ $milestone['title'] }}</span>
                    <span class="ml-auto font-mono text-meta text-mute">({{ count(array_filter($milestone['tasks'], fn($t) => $t['status'] === 'done')) }}/{{ count($milestone['tasks']) }})</span>
                    <span class="transition-transform duration-120 text-mute" :class="open && 'rotate-180'">▼</span>
                </button>

                <div x-show="open" x-cloak class="border-t border-border-soft bg-surface px-4 py-3 space-y-3">
                    @if($milestone['summary'])
                        <p class="text-body-sm text-t2">{{ $milestone['summary'] }}</p>
                    @endif

                    <ul class="space-y-2">
                        @foreach($milestone['tasks'] as $task)
                            <li class="flex items-center gap-2 text-sm">
                                <span class="h-2 w-2 rounded-full {{ $task['status'] === 'done' ? 'bg-ok' : ($task['status'] === 'ongoing' ? 'bg-status-working' : 'bg-status-idle') }}"></span>
                                <span class="font-mono text-meta text-hi">{{ $task['key'] }}</span>
                                <span class="text-text">{{ $task['title'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @empty
        <div class="py-12 text-center">
            <p class="text-body text-t2">No roadmap yet. Ensure <code class="font-mono text-meta">agents/ROADMAP.md</code> exists in the repo.</p>
        </div>
    @endforelse
</div>
