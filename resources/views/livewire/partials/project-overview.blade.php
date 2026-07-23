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
        <h2 class="text-lg font-semibold text-hi">Project Summary</h2>
        @if($this->projectSummaryText)
            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left text-body-sm text-text hover:text-hi cursor-pointer">
                    <span>View project summary</span>
                    <span class="transition-transform duration-120" :class="open && 'rotate-180'">▼</span>
                </button>
                <div x-show="open" x-cloak class="mt-2">
                    <pre class="whitespace-pre-wrap font-mono text-xs text-t2 leading-relaxed">{{ $this->projectSummaryText }}</pre>
                </div>
            </div>
        @else
            <p class="text-body-sm text-mute">No summary yet. Consensus writes the project memory at plan approval.</p>
        @endif
    </div>

    <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
        <h2 class="text-lg font-semibold text-hi">Agreed Specs</h2>
        @forelse($this->agreedSpecs as $q)
            <div class="border-b border-border-soft pb-2 last:border-0 last:pb-0">
                <p class="text-body-sm text-text">{{ $q->text }}</p>
                <p class="text-body-sm text-t2">→ {{ $q->answer }}</p>
            </div>
        @empty
            <p class="text-body-sm text-mute">No settled specs yet.</p>
        @endforelse
    </div>

    <div class="rounded-lg border border-border bg-surface-card p-4 space-y-3">
        <h2 class="text-lg font-semibold text-hi">Recent Consensus</h2>
        @php
            $groupedConsensus = [];
            $currentGroup = [];
            foreach ($this->recentConsensus as $msg) {
                if ($msg->role === \App\Enums\MessageRole::User) {
                    $currentGroup[] = $msg;
                } else {
                    if (!empty($currentGroup)) {
                        $groupedConsensus[] = $currentGroup;
                        $currentGroup = [];
                    }
                    $groupedConsensus[] = [$msg];
                }
            }
            if (!empty($currentGroup)) {
                $groupedConsensus[] = $currentGroup;
            }
        @endphp
        @if(empty($groupedConsensus))
            <p class="text-body-sm text-mute">No consensus messages yet.</p>
        @else
            @foreach($groupedConsensus as $group)
                @php
                    $isUserGroup = count($group) > 1 && $group[0]->role === \App\Enums\MessageRole::User;
                    $singleMsg = $group[0];
                @endphp
                <div class="border-b border-border-soft pb-2 last:border-0 last:pb-0" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="flex w-full items-center gap-2 text-left cursor-pointer hover:opacity-80">
                        @if($isUserGroup)
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-surface-active text-mute">User</span>
                            <span class="text-xs text-mute">{{ count($group) }} answers</span>
                        @else
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-surface-active text-mute">{{ $singleMsg->role->value }}</span>
                            <span class="text-xs text-mute">{{ $singleMsg->created_at->diffForHumans() }}</span>
                        @endif
                        <span class="ml-auto text-mute transition-transform duration-120" :class="open && 'rotate-180'">▼</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2 space-y-2">
                        @foreach($group as $msg)
                            <p class="text-sm text-t2">{{ strip_tags($msg->content) }}</p>
                        @endforeach
                    </div>
                    @if(!$isUserGroup)
                        <p class="text-sm text-t2 line-clamp-1 mt-1" x-show="!open">{{ Str::limit(strip_tags($singleMsg->content), 150) }}</p>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
