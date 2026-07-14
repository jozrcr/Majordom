<div class="flex h-full flex-col gap-6 overflow-y-auto p-6">
    <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
        <h2 class="text-lg font-semibold text-hi">Project Facts</h2>
        <div class="grid grid-cols-2 gap-4 font-mono text-sm">
            <div>
                <span class="text-mute">Name:</span>
                <span class="ml-2 text-hi">{{ $project->name }}</span>
            </div>
            <div>
                <span class="text-mute">Status:</span>
                <span class="ml-2 rounded px-2 py-0.5 text-xs font-semibold uppercase tracking-wide {{ $project->status === \App\Enums\ProjectStatus::Working ? 'bg-accent-tint text-accent' : 'bg-surface-active text-mute' }}">
                    {{ $project->status->label() ?? $project->status->value }}
                </span>
            </div>
            <div class="col-span-2">
                <span class="text-mute">Repo:</span>
                <span class="ml-2 text-hi">{{ $project->repo_path }}</span>
            </div>
            <div>
                <span class="text-mute">Test Command:</span>
                <span class="ml-2 text-hi">{{ $project->test_command ?? 'N/A' }}</span>
            </div>
            <div>
                <span class="text-mute">Workflow:</span>
                <span class="ml-2 text-hi">{{ $project->workflow?->name ?? 'default' }}</span>
            </div>
            <div class="col-span-2">
                <span class="text-mute">Last Activity:</span>
                <span class="ml-2 text-hi">{{ $project->last_activity_at ? $project->last_activity_at->diffForHumans() : 'Never' }}</span>
            </div>
        </div>
    </div>

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

    <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
        <h2 class="text-lg font-semibold text-hi">Recent Consensus</h2>
        @forelse($this->recentConsensus as $msg)
            <div class="border-b border-border-soft pb-2 last:border-0 last:pb-0" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-2 text-left cursor-pointer hover:opacity-80">
                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-surface-active text-mute">{{ $msg->role->value }}</span>
                    <span class="text-xs text-mute">{{ $msg->created_at->diffForHumans() }}</span>
                    <span class="ml-auto text-mute transition-transform duration-120" :class="open && 'rotate-180'">▼</span>
                </button>
                <div x-show="open" x-cloak class="mt-2">
                    <p class="text-sm text-t2">{{ strip_tags($msg->content) }}</p>
                </div>
                <p class="text-sm text-t2 line-clamp-1 mt-1" x-show="!open">{{ Str::limit(strip_tags($msg->content), 150) }}</p>
            </div>
        @empty
            <p class="text-body-sm text-mute">No consensus messages yet.</p>
        @endforelse
    </div>
</div>
