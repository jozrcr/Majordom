<div class="mx-auto flex h-[calc(100vh-52px)] max-w-6xl gap-0 px-6">
    {{-- Static poll (reliability floor): a conditional wire:poll on the root
         never initializes when morphed in later. Echo pushes are the fast
         path; this catches anything the socket misses. --}}
    <div wire:poll.3s class="hidden"></div>
    <div class="flex h-full min-w-0 flex-1 flex-col justify-start">
        <div class="py-4 flex items-center gap-3 border-b border-border pr-4">
            <div class="min-w-0">
                <h1 class="truncate text-title font-semibold text-hi">{{ $project->name }}</h1>
                <p class="truncate font-mono text-meta text-mute" title="{{ $project->repo_path }}">{{ $project->repo_path }}</p>
            </div>
            <div class="min-w-0 flex flex-col">
                <span class="w-fit rounded-[5px] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em]">Architect</span>
                <span class="pl-1 font-mono text-meta text-mute">{{ config('majordom.architect.model') }}</span>
            </div>
                @if($openCount > 0)
                <span class="ml-auto rounded-full border px-2.5 py-0.5 font-mono text-[10.5px] font-semibold tracking-[.06em] text-accent">{{ $openCount }} question{{ $openCount > 1 ? 's' : '' }} remaining</span>
            @endif
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto py-5">
            @if(empty($sessions))
                <div class="py-24 text-center space-y-3">
                    <div class="inline-flex gap-2 justify-center">
                        <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                        <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                        <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                    </div>
                    <p class="text-display font-semibold text-hi">{{ $project->name }}</p>
                    <p class="text-body text-t2">No memory yet. Describe the first feature to wake the Architect.</p>
                </div>
            @else
                @foreach($sessions as $idx => $session)
                    @if($session['closed'])
                        <div class="max-w-[640px]" x-data="{ open: false }" id="session-{{ $idx }}"
                             @open-session.window="if ($event.detail.session === {{ $idx }}) { open = true; $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' })) }">
                            <button type="button" @click="open = !open"
                                    class="flex w-full cursor-pointer items-center gap-2 rounded-md border border-border-soft px-3 py-2 font-mono text-meta text-mute transition-colors duration-120 hover:bg-surface-active hover:text-t3">
                                <span class="transition-transform duration-120" :class="open && 'rotate-90'">›</span>
                                session {{ $idx + 1 }} · {{ $session['messages']->count() }} messages · ended {{ $session['endedAt']->diffForHumans() }}
                            </button>
                            <div class="mt-3 space-y-4" x-show="open" x-cloak>
                                @foreach($session['messages'] as $message)
                                    @include('livewire.partials.consensus-message', ['message' => $message, 'questionsByMessage' => $questionsByMessage])
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div id="session-{{ $idx }}" class="contents"
                             @open-session.window="if ($event.detail.session === {{ $idx }}) { $el.scrollIntoView({ behavior: 'smooth', block: 'start' }) }" x-data>
                        @foreach($session['messages'] as $message)
                            @include('livewire.partials.consensus-message', ['message' => $message, 'questionsByMessage' => $questionsByMessage])
                        @endforeach
                        </div>
                    @endif
                @endforeach
            @endif

            @if($this->consensusPending && !$this->thinking)
                {{-- Plan-approval moment (design §2.6): the human owns this gate. --}}
                <div class="max-w-[640px] rounded-lg border bg-surface-raised p-4 space-y-3">
                    <p class="font-mono text-micro uppercase tracking-[.14em] text-accent">Plan approval</p>
                    <p class="text-body-sm text-text">Consensus reached. Approve to let the Architect write the project memory — architecture.md, roadmap.md and the first task brief. Not confident yet? Keep talking below; the scope stays open.</p>
                    <div class="flex items-center gap-3">
                        <button wire:click="approvePlan" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-body-sm font-semibold disabled:opacity-55">
                            <span wire:loading.remove wire:target="approvePlan">Approve plan</span>
                            <span wire:loading wire:target="approvePlan">Approving…</span>
                        </button>
                        <span class="font-mono text-meta text-faint">writes project memory · nothing touches your repo</span>
                    </div>
                </div>
            @endif

            @if($this->plannedTask)
                <div class="max-w-[640px] rounded-lg border border-border-strong bg-surface-raised p-4 space-y-3">
                    <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">PLAN READY</p>
                    <p class="text-body-sm text-text">First task: <span class="font-mono">{{ $this->plannedTask['key'] }}</span> — {{ $this->plannedTask['title'] }}</p>
                    <div class="flex items-center gap-3">
                        <button wire:click="startBuild" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-body-sm font-semibold disabled:opacity-55">
                            <span wire:loading.remove wire:target="startBuild">Start build</span>
                            <span wire:loading wire:target="startBuild">Starting…</span>
                        </button>
                        <span class="font-mono text-meta text-faint">Builder runs in an isolated worktree</span>
                    </div>
                </div>
            @endif

            @if($this->latestExecution)
                <div class="max-w-[640px] rounded-lg border border-border bg-surface-card px-4 py-3">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-mono text-meta text-mute">execution #{{ $this->latestExecution->id }}</span>
                        @foreach($this->latestExecution->nodes as $index => $node)
                            @if($index > 0)<span class="text-faint">·</span>@endif
                            @php
                                $nodeLed = match ($node->status->value) {
                                    'running' => 'bg-status-working animate-led-pulse',
                                    'completed' => 'bg-ok',
                                    'failed' => 'bg-status-failed',
                                    'waiting_human' => 'bg-accent led-glow',
                                    default => 'bg-status-idle',
                                };
                            @endphp
                            <div class="flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full {{ $nodeLed }}"></span>
                                <span class="font-mono text-meta">{{ $node->type }}</span>
                            </div>
                        @endforeach
                    </div>
                    @if($this->latestExecution->status === \App\Enums\ExecutionStatus::Parked)
                        <p class="mt-2 font-mono text-meta text-failed-text">parked · {{ $this->latestExecution->meta['parked_reason'] ?? 'unknown' }}</p>
                    @endif
                </div>
            @endif

            @if($this->reviewApproval)
                <div class="max-w-[640px] rounded-lg border bg-surface-raised p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="rounded-[5px] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em]">Gate</span>
                        <p class="text-body-sm text-text">{{ $this->reviewApproval->title }}</p>
                        <div class="ml-auto font-mono text-meta">
                            @if($this->reviewApproval->payload['testsPassed'] === true)
                                <span class="text-ok">tests ✓</span>
                            @elseif($this->reviewApproval->payload['testsPassed'] === false)
                                <span class="text-failed-text">tests ✗</span>
                            @else
                                <span class="text-mute">no tests</span>
                            @endif
                        </div>
                    </div>

                    <p class="font-mono text-meta text-mute">{{ count($this->reviewApproval->payload['filesChanged'] ?? []) }} file(s) · builder: {{ config('majordom.builder.gateway_model') }}</p>

                    <p class="text-body-sm text-t2">{{ $this->reviewApproval->payload['verdict']['summary'] ?? '' }}</p>
                    @if(!empty($this->reviewApproval->payload['verdict']['comments']))
                        <ul class="text-caption text-t3 list-disc pl-4 space-y-1">
                            @foreach($this->reviewApproval->payload['verdict']['comments'] as $comment)
                                <li>{{ $comment }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="max-h-[420px] overflow-auto rounded-md border border-border-soft bg-surface font-mono text-[12px] leading-[1.75]">
                        @php
                            $diffLines = explode("\n", $this->reviewApproval->payload['diff'] ?? '');
                        @endphp
                        @foreach($diffLines as $line)
                            @php
                                $cls = 'text-t3';
                                if (str_starts_with($line, '+++') || str_starts_with($line, '---')) { $cls = 'text-t3'; }
                                elseif (str_starts_with($line, '+')) { $cls = 'bg-diff-add-bg text-diff-add-text'; }
                                elseif (str_starts_with($line, '-')) { $cls = 'bg-diff-del-bg text-diff-del-text'; }
                                elseif (str_starts_with($line, '@@')) { $cls = 'bg-diff-hunk-bg text-diff-hunk-text'; }
                                elseif (str_starts_with($line, 'diff --git')) { $cls = 'text-t2 font-semibold'; }
                            @endphp
                            <div class="whitespace-pre px-4 {{ $cls }}">{{ $line }}</div>
                        @endforeach
                    </div>

                    <input type="text" wire:model="gateComment" placeholder="Comment (required to reject)…" class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint">
                    @error('gateComment') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror

                    <div class="flex items-center gap-3">
                        <button wire:click="approveReview" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-body-sm font-semibold disabled:opacity-55">
                            <span wire:loading.remove wire:target="approveReview">Approve</span>
                            <span wire:loading wire:target="approveReview">Approving…</span>
                        </button>
                        <button wire:click="rejectReview" wire:loading.attr="disabled" class="rounded-lg border px-3 py-1.5 text-body-sm font-semibold text-failed-text disabled:opacity-55 hover:bg-failed-tint">
                            <span wire:loading.remove wire:target="rejectReview">Reject</span>
                            <span wire:loading wire:target="rejectReview">Rejecting…</span>
                        </button>
                    </div>
                </div>
            @endif

            @if($this->commitSuggestion)
                <div class="max-w-[640px] rounded-lg border border-border bg-surface-card p-4 space-y-3">
                    <p class="font-mono text-micro uppercase tracking-[.14em] text-ok">COMMIT READY</p>
                    <p class="font-mono text-meta text-mute">branch {{ $this->commitSuggestion->branch }} · you commit — Majordom never does</p>
                    <textarea readonly rows="6" class="w-full rounded-md border border-border-soft bg-surface p-3 font-mono text-[12px] text-body">{{ $this->commitSuggestion->message }}</textarea>
                    <div x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="cursor-pointer font-mono text-meta text-mute hover:text-t3">view diff</button>
                        <template x-if="true"></template>
                        <div x-show="open" x-cloak>
                        <div class="mt-2 max-h-[420px] overflow-auto rounded-md border border-border-soft bg-surface font-mono text-[12px] leading-[1.75]">
                            @php
                                $commitDiffLines = explode("\n", $this->commitSuggestion->diff ?? '');
                            @endphp
                            @foreach($commitDiffLines as $line)
                                @php
                                    $cls = 'text-t3';
                                    if (str_starts_with($line, '+++') || str_starts_with($line, '---')) { $cls = 'text-t3'; }
                                    elseif (str_starts_with($line, '+')) { $cls = 'bg-diff-add-bg text-diff-add-text'; }
                                    elseif (str_starts_with($line, '-')) { $cls = 'bg-diff-del-bg text-diff-del-text'; }
                                    elseif (str_starts_with($line, '@@')) { $cls = 'bg-diff-hunk-bg text-diff-hunk-text'; }
                                    elseif (str_starts_with($line, 'diff --git')) { $cls = 'text-t2 font-semibold'; }
                                @endphp
                                <div class="whitespace-pre px-4 {{ $cls }}">{{ $line }}</div>
                            @endforeach
                        </div>
                        </div>
                    </div>
                    <input type="text" wire:model="commitComment" placeholder="Comment (required for rework / reject)…"
                           class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body-sm text-hi placeholder:text-faint">
                    @error('commitComment') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
                    <div class="flex items-center gap-2">
                        <button wire:click="applyCommit" wire:confirm="Squash-merge {{ $this->commitSuggestion->branch }} into your current branch and commit?" wire:loading.attr="disabled"
                                class="rounded-lg bg-accent px-3 py-1.5 text-body-sm font-semibold text-accent-ink disabled:opacity-55">
                            <span wire:loading.remove wire:target="applyCommit">Commit</span>
                            <span wire:loading wire:target="applyCommit">Committing…</span>
                        </button>
                        <button wire:click="reworkCommit" wire:loading.attr="disabled"
                                class="rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-semibold text-[#c7d2df] hover:bg-surface-active disabled:opacity-55">
                            <span wire:loading.remove wire:target="reworkCommit">Rework</span>
                            <span wire:loading wire:target="reworkCommit">Restarting…</span>
                        </button>
                        <button wire:click="rejectCommit" wire:loading.attr="disabled"
                                class="rounded-lg border border-failed-border px-3 py-1.5 text-body-sm font-semibold text-failed-text hover:bg-failed-tint disabled:opacity-55">Reject</button>
                    </div>
                </div>
            @endif

            @if($this->thinking)
                <div class="flex items-center gap-2.5">
                    <span class="spinner"></span>
                    <span class="font-mono text-meta text-mute">{{ $this->thinkingLabel }}</span>
                </div>
            @endif
        </div>

        <div class="border-t border-border py-4">
            <form wire:submit="send" class="flex gap-2">
                <textarea wire:model="draft" rows="2" placeholder="Describe what to build…" class="flex-1 rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint" @disabled($this->thinking)></textarea>
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-semibold text-[#c7d2df] hover:bg-surface-active disabled:opacity-55" @disabled($this->thinking)>
                    <span wire:loading.remove wire:target="send">Send</span>
                    <span wire:loading wire:target="send">Sending…</span>
                </button>
            </form>
            @error('draft') <p class="text-caption text-failed-text mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    <aside class="hidden w-[330px] shrink-0 flex-col border-l border-border lg:flex">
        <div class="flex items-center gap-2 px-4 py-4">
            <span class="font-mono text-micro uppercase tracking-[.14em] text-mute">Activity</span>
            <span class="h-2 w-2 rounded-full bg-status-working animate-led-pulse"></span>
            <span class="font-mono text-meta text-faint">live</span>
        </div>
        <div class="flex-1 overflow-y-auto">
            @forelse($timelineGroups as $group)
                @php $targetSession = $group['key'] === 'consensus' ? 0 : ($executionSessionMap[$group['key']] ?? null); @endphp
                <button type="button"
                        @if($targetSession !== null) onclick="window.dispatchEvent(new CustomEvent('open-session', { detail: { session: {{ $targetSession }} } }))" @endif
                        class="block w-full cursor-pointer border-b border-border-soft bg-surface-card px-4 py-1.5 text-left font-mono text-micro uppercase tracking-[.14em] text-mute transition-colors duration-120 hover:bg-surface-active hover:text-accent"
                        title="show the linked chat session">
                    {{ $group['key'] === 'consensus' ? 'consensus' : 'execution #'.$group['key'] }} <span class="normal-case tracking-normal text-faint">↖</span>
                </button>
                @foreach($group['events'] as $ev)
                    <div class="border-b border-border-soft px-4 py-2.5 cursor-pointer {{ str_contains($ev->name, 'waiting_human') || str_contains($ev->name, 'question') ? 'bg-accent-tint' : '' }} {{ $selectedEventId === $ev->id ? 'bg-surface-active' : '' }}"
                         @if(!empty($ev->payload['messageId']))
                             onclick="document.getElementById('msg-{{ $ev->payload['messageId'] }}')?.scrollIntoView({behavior: 'smooth', block: 'center'})"
                         @endif>
                        <button type="button" wire:click="selectEvent({{ $ev->id }})" class="block w-full text-left">
                            <div class="flex items-baseline gap-2">
                                <span class="font-mono text-meta text-mute">{{ $ev->created_at->format('H:i:s') }}</span>
                                <span class="font-mono text-[11.5px] font-medium text-text">{{ $ev->name }}</span>
                                @php $actor = $ev->actor; @endphp
                                <span class="ml-auto rounded-[5px] px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[.1em]">{{ $actor }}</span>
                            </div>
                            @if(!empty($ev->payload))
                                <p class="mt-0.5 truncate text-caption">{{ collect($ev->payload)->map(fn ($v, $k) => is_scalar($v) ? "{$k}: {$v}" : null)->filter()->take(2)->implode(' · ') }}</p>
                            @endif
                        </button>
                    </div>
                    @if($selectedEventId === $ev->id)
                        <div class="border-b border-border-soft bg-surface px-4 py-3 space-y-2">
                            @php $detail = $this->selectedEventDetail; @endphp
                            @if($detail)
                                <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">payload</p>
                                <pre class="max-h-[200px] overflow-auto rounded-md border border-border-soft bg-bg p-2 font-mono text-[11px] leading-relaxed text-t3">{{ json_encode($detail['event']->payload ?: new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @if($detail['node'])
                                    <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">node · {{ $detail['node']->status->value }}@if($detail['node']->started_at) · {{ $detail['node']->started_at->format('H:i:s') }}@endif @if($detail['node']->finished_at)→ {{ $detail['node']->finished_at->format('H:i:s') }}@endif</p>
                                    @php $out = collect($detail['node']->output ?? []); @endphp
                                    @if($out->has('rawLog'))
                                        <div x-data="{ open: false }">
                                            <button type="button" @click="open = !open" class="cursor-pointer font-mono text-meta text-mute hover:text-t3">raw log</button>
                                            <pre x-show="open" x-cloak class="mt-1 max-h-[260px] overflow-auto rounded-md border border-border-soft bg-bg p-2 font-mono text-[11px] text-mute">{{ $out['rawLog'] }}</pre>
                                        </div>
                                    @endif
                                    @if($out->has('diff') && $out['diff'])
                                        <div x-data="{ open: false }">
                                            <button type="button" @click="open = !open" class="cursor-pointer font-mono text-meta text-mute hover:text-t3">diff</button>
                                            <pre x-show="open" x-cloak class="mt-1 max-h-[260px] overflow-auto rounded-md border border-border-soft bg-bg p-2 font-mono text-[11px] text-t3">{{ $out['diff'] }}</pre>
                                        </div>
                                    @endif
                                    @php $rest = $out->except(['rawLog', 'diff']); @endphp
                                    @if($rest->isNotEmpty())
                                        <pre class="max-h-[200px] overflow-auto rounded-md border border-border-soft bg-bg p-2 font-mono text-[11px] leading-relaxed text-t3">{{ json_encode($rest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @endif
                                @endif
                            @endif
                        </div>
                    @endif
                @endforeach
            @empty
                <p class="px-4 py-6 font-mono text-meta text-faint">no activity yet</p>
            @endforelse
        </div>
    </aside>
</div>

@script
<script>
    if (window.Echo) {
        window.Echo.channel('project.{{ $project->id }}')
            .listen('.domain-event', () => { $wire.dispatch('timeline-bump'); });
    }
</script>
@endscript
