<div class="flex h-full flex-col gap-6 p-6">
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

                <div x-show="open" x-cloak class="border-t border-border-soft bg-surface px-4 py-3 space-y-3  overflow-y-scroll">
                    @if($milestone['summary'])
                        <p class="text-body-sm text-t2 mb-3">{{ $milestone['summary'] }}</p>
                    @endif

                    <ul class="space-y-2">
                        @foreach($milestone['tasks'] as $task)
                            <li>
                                <div x-data="{ open: false }" class="">
                                    <button type="button" @click="open = !open" class="flex w-full items-center gap-2 text-sm text-left hover:text-hi transition-colors">
                                        <span class="h-2 w-2 rounded-full {{ $task['status'] === 'done' ? 'bg-ok' : ($task['status'] === 'ongoing' ? 'bg-status-working' : 'bg-status-idle') }}"></span>
                                        <span class="font-mono text-meta text-hi">{{ $task['key'] }}</span>
                                        <span class="text-text">{{ $task['title'] }}</span>
                                        <span class="ml-auto transition-transform duration-120 text-mute text-xs" :class="open && 'rotate-180'">▼</span>
                                    </button>
                                    <div x-show="open" x-cloak class="mt-2 pl-4 border-l border-border-soft">
                                        @if($task['description'])
                                            <pre class="whitespace-pre-wrap font-mono text-xs text-t2 leading-relaxed">{{ $task['description'] }}</pre>
                                        @else
                                            <p class="text-body-sm text-mute italic">No task brief yet.</p>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @empty
        <div class="py-12 text-center">
            <p class="text-body text-t2">No roadmap yet. Ensure <code class="font-mono text-meta">agents/ROADMAP.md</code> exists in the repo or run consensus.</p>
        </div>
    @endforelse
</div>
