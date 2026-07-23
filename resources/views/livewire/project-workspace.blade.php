<div class="mx-auto flex h-[calc(100vh-52px)] w-full max-w-[1920px] gap-0 px-6">
    {{-- Static poll (reliability floor): a conditional wire:poll on the root
         never initializes when morphed in later. Echo pushes are the fast
         path; this catches anything the socket misses. --}}
    <div wire:poll.3s class="hidden"></div>
    <div class="flex h-full min-w-0 flex-1 flex-col justify-start">
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <div class="min-w-0">
                <h1 class="truncate text-title font-semibold text-hi">{{ $project->name }}</h1>
                <p class="truncate font-mono text-meta text-mute" title="{{ $project->repo_path }}">{{ $project->repo_path }}</p>
            </div>
            @if($project->status)
                <span class="ml-4 shrink-0 rounded-[5px] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em] text-accent bg-accent-tint">
                    {{ $project->status->label() }}
                </span>
            @endif
        </div>

        <div class="flex items-center gap-4 border-b border-border px-1">
            <button wire:click="$set('tab', 'chat')" class="px-3 py-2 text-sm font-medium transition-colors {{ $tab === 'chat' ? 'text-accent border-b-2 border-accent' : 'text-mute hover:text-t3' }}">Chat</button>
            <button wire:click="$set('tab', 'overview')" class="px-3 py-2 text-sm font-medium transition-colors {{ $tab === 'overview' ? 'text-accent border-b-2 border-accent' : 'text-mute hover:text-t3' }}">Overview</button>
            <button wire:click="$set('tab', 'stats')" class="px-3 py-2 text-sm font-medium transition-colors {{ $tab === 'stats' ? 'text-accent border-b-2 border-accent' : 'text-mute hover:text-t3' }}">Stats</button>
            <button wire:click="$set('tab', 'roadmap')" class="px-3 py-2 text-sm font-medium transition-colors {{ $tab === 'roadmap' ? 'text-accent border-b-2 border-accent' : 'text-mute hover:text-t3' }}">Roadmap</button>
            <button wire:click="$set('tab', 'exchanges')" class="px-3 py-2 text-sm font-medium transition-colors {{ $tab === 'exchanges' ? 'text-accent border-b-2 border-accent' : 'text-mute hover:text-t3' }}">Exchanges</button>
            <button wire:click="$set('tab', 'settings')" class="px-3 py-2 text-sm font-medium transition-colors {{ $tab === 'settings' ? 'text-accent border-b-2 border-accent' : 'text-mute hover:text-t3' }}">Settings</button>
        </div>

        @if($tab === 'chat')
            <div class="py-4 flex items-center gap-3 border-b border-border pr-4">
                <div class="min-w-0 flex flex-col">
                    <span class="w-fit rounded-[5px] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em]">Architect</span>
                    <span class="pl-1 font-mono text-meta text-mute">{{ config('majordom.architect.model') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <label class="font-mono text-meta text-mute">workflow</label>
                    <select wire:model.live="workflowId" class="rounded border border-border bg-surface px-2 py-1 text-xs font-mono text-hi">
                        <option value="">default</option>
                        @foreach($workflows as $wf)
                            <option value="{{ $wf->id }}">{{ $wf->name }}</option>
                        @endforeach
                    </select>
                </div>
                    @if($openCount > 0)
                    <span class="ml-auto rounded-full border px-2.5 py-0.5 font-mono text-[10.5px] font-semibold tracking-[.06em] text-accent">{{ $openCount }} question{{ $openCount > 1 ? 's' : '' }} remaining</span>
                @endif
            </div>

            @if($runNotice)
                <div class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-accent/40 bg-accent-tint px-4 py-2.5">
                    <p class="text-body-sm text-accent">{{ $runNotice }}</p>
                    <button wire:click="$set('runNotice', null)" class="font-mono text-meta text-mute hover:text-t3">dismiss</button>
                </div>
            @endif

            @include('livewire.partials.project-pipeline')
            @include('livewire.partials.node-inspector')

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
                            <div x-data="{ open: false }" id="session-{{ $idx }}"
                                 @open-session.window="if ($event.detail.session === {{ $idx }}) { open = true; $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' })) }">
                                <button type="button" @click="open = !open"
                                        class="flex w-full max-w-[640px] cursor-pointer items-center gap-2 rounded-md border border-border-soft px-3 py-2 font-mono text-meta text-mute transition-colors duration-120 hover:bg-surface-active hover:text-t3">
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
                    {{-- Plan-approval moment (design §2.6): the human owns this gate.
                         M16-B: the card shows the recap the owner is agreeing to —
                         summary + roadmap — and is revision-aware. A revision to an
                         already-approved plan preserves built work; a first plan
                         writes fresh memory. --}}
                    @php $plan = $this->proposedPlan; $isRevision = $this->planExists; @endphp
                    <div class="max-w-[640px] rounded-lg border bg-surface-raised p-4 space-y-3">
                        <p class="font-mono text-micro uppercase tracking-[.14em] text-accent">{{ $isRevision ? 'Revised plan' : 'Plan approval' }}</p>

                        @if($plan)
                            @if(!empty($plan['summary']))
                                <p class="text-body-sm text-t2">{{ $plan['summary'] }}</p>
                            @endif
                            @if(!empty($plan['roadmap_md']))
                                <details class="text-caption text-t3" open>
                                    <summary class="cursor-pointer font-mono text-meta text-mute hover:text-t3">roadmap</summary>
                                    <pre class="mt-2 max-h-[280px] overflow-auto rounded-md border border-border-soft bg-bg p-2 whitespace-pre-wrap text-caption text-t2">{{ $plan['roadmap_md'] }}</pre>
                                </details>
                            @endif
                        @endif

                        <p class="text-body-sm text-text">
                            @if($isRevision)
                                Approve to update the roadmap. Existing milestones and tasks are preserved — reworded or added, never renumbered — and the build loop is reset to the first pending task. Not sure yet? Keep talking below; the scope stays open.
                            @else
                                Consensus reached. Approve to let the Architect write the project memory — architecture.md, roadmap.md and the first task brief. Not confident yet? Keep talking below; the scope stays open.
                            @endif
                        </p>
                        <div class="flex items-center gap-3">
                            <button wire:click="approvePlan" wire:confirm="{{ $isRevision ? 'Approve this revised plan? Majordom will update the roadmap and reset the build loop.' : 'Approve this plan and start the build? Majordom will begin executing immediately.' }}" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-body-sm font-semibold disabled:opacity-55">
                                <span wire:loading.remove wire:target="approvePlan">{{ $isRevision ? 'Approve revision' : 'Approve plan' }}</span>
                                <span wire:loading wire:target="approvePlan">Approving…</span>
                            </button>
                            <span class="font-mono text-meta text-faint">{{ $isRevision ? 'updates the roadmap · preserves built work' : 'writes project memory · nothing touches your repo' }}</span>
                        </div>
                    </div>
                @endif

                {{-- M15: the "Architect stalled" card is gone. With the tool
                     contract a consensus turn always ends in a known state (a
                     question to answer, a plan to approve, or a plain reply that
                     is simply the owner's turn) — there is no stall to recover. --}}

                @if($this->plannedTask)
                    <div class="max-w-[640px] rounded-lg border border-border-strong bg-surface-raised p-4 space-y-3">
                        <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">PLAN READY</p>
                        <p class="text-body-sm text-text">First task: <span class="font-mono">{{ $this->plannedTask['key'] }}</span> — {{ $this->plannedTask['title'] }}</p>
                        <div class="flex flex-wrap items-center gap-4 font-mono text-meta text-mute">
                            <label class="flex cursor-pointer items-center gap-1.5"><input type="radio" wire:model="buildProfile" value="attended" class="accent-[#e2a33b]"> attended</label>
                            <label class="flex cursor-pointer items-center gap-1.5"><input type="radio" wire:model="buildProfile" value="overnight" class="accent-[#e2a33b]"> overnight <span class="text-faint">(auto-review, spend-capped)</span></label>
                            <label class="flex cursor-pointer items-center gap-1.5"><input type="radio" wire:model="buildProfile" value="full_auto" class="accent-[#e2a33b]"> full auto <span class="text-faint">(auto-merges milestones)</span></label>
                        </div>
                        <div class="flex items-center gap-3">
                            <button wire:click="startBuild" wire:loading.attr="disabled" class="rounded-lg bg-accent px-3 py-1.5 text-body-sm font-semibold text-accent-ink disabled:opacity-55">
                                <span wire:loading.remove wire:target="startBuild">Start build</span>
                                <span wire:loading wire:target="startBuild">Starting…</span>
                            </button>
                            <span class="font-mono text-meta text-faint">Builder runs in an isolated worktree</span>
                        </div>
                    </div>
                @endif

                @if($this->retryableTask)
                    <div class="max-w-[640px] rounded-lg border border-status-failed/40 bg-surface-raised p-4 space-y-3">
                        <p class="font-mono text-micro uppercase tracking-[.14em] text-failed-text">Task stuck — recover</p>
                        <p class="text-body-sm text-text">
                            <span class="font-mono">{{ $this->retryableTask['key'] }}</span> — {{ $this->retryableTask['title'] }}
                        </p>
                        @if($this->retryableTask['reason'])
                            <p class="font-mono text-meta text-mute">{{ $this->retryableTask['reason'] }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-3">
                            <button wire:click="retryTask('{{ $this->retryableTask['key'] }}', false)" wire:loading.attr="disabled" class="rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-medium text-hi hover:bg-surface-active disabled:opacity-55">
                                Retry with a fresh brief
                            </button>
                            <button wire:click="retryTask('{{ $this->retryableTask['key'] }}', true)" wire:loading.attr="disabled" class="rounded-lg bg-accent px-3 py-1.5 text-body-sm font-semibold text-accent-ink disabled:opacity-55">
                                Retry on the frontier Builder
                            </button>
                        </div>
                        <p class="font-mono text-meta text-faint">regenerates the brief from the current roadmap, then rebuilds</p>
                    </div>
                @endif

                {{-- M16-A: milestones the owner set aside ("Not yet — keep it ready").
                     The branch/worktree are intact; merging is one click away, so the
                     "deferred" state is never a dead end. --}}
                @foreach($this->deferredMilestoneGates as $gate)
                    @php $dRecap = $gate->payload['recap'] ?? []; $dStat = $dRecap['diffstat'] ?? null; @endphp
                    <div class="max-w-[640px] rounded-lg border border-border-strong bg-surface-raised p-4 space-y-3">
                        <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">Merge later — ready when you are</p>
                        <p class="text-body-sm text-text">{{ $gate->title }}</p>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-meta text-mute">
                            @if(!empty($dRecap['branch']))<span>branch: <span class="text-t3">{{ $dRecap['branch'] }}</span></span>@endif
                            @if($dStat)
                                <span>· {{ $dStat['files'] }} file(s)</span>
                                <span class="text-diff-add-text">+{{ $dStat['insertions'] }}</span>
                                <span class="text-diff-del-text">−{{ $dStat['deletions'] }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <button wire:click="mergeDeferred({{ $gate->id }})" wire:loading.attr="disabled" wire:target="mergeDeferred({{ $gate->id }})" class="rounded-lg bg-accent px-3 py-1.5 text-body-sm font-semibold text-accent-ink disabled:opacity-55">
                                <span wire:loading.remove wire:target="mergeDeferred({{ $gate->id }})">Merge now &amp; start next</span>
                                <span wire:loading wire:target="mergeDeferred({{ $gate->id }})">Merging…</span>
                            </button>
                            <span class="font-mono text-meta text-faint">promotes {{ $dRecap['branch'] ?? 'the milestone branch' }} into your checked-out branch</span>
                        </div>
                    </div>
                @endforeach

                @if($this->latestExecution)
                    <div class="max-w-[640px] rounded-lg border border-border bg-surface-card px-4 py-3">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-mono text-meta text-mute">execution #{{ $this->latestExecution->id }}</span>
                            @if($this->builderBadge)
                                <span class="font-mono text-meta {{ $this->builderBadge['downgraded'] ? 'text-accent' : 'text-mute' }}">
                                    · {{ $this->builderBadge['label'] }} Builder
                                </span>
                            @endif
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
                            <div class="mt-2 flex items-center gap-2">
                                <button wire:click="resumeParked" class="rounded bg-accent px-3 py-1.5 text-xs font-medium text-accent-ink hover:opacity-90 transition-opacity">
                                    <span wire:loading.remove wire:target="resumeParked">Retry failed step</span>
                                    <span wire:loading wire:target="resumeParked">Retrying…</span>
                                </button>
                                <button wire:click="abandonParked" wire:confirm="Abandon this run? Remaining steps will be marked failed." class="rounded border border-border px-3 py-1.5 text-xs font-medium text-mute hover:bg-surface-chip transition-colors">Abandon run</button>
                            </div>
                        @endif
                    </div>
                @endif

                @if($this->commitSuggestion)
                    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
                        <div class="max-w-[720px] w-full max-h-[85vh] overflow-auto rounded-lg border border-border bg-surface-raised p-5 space-y-3">
                            <p class="font-mono text-micro uppercase tracking-[.14em] text-ok">COMMIT READY</p>
                            <p class="font-mono text-meta text-mute">branch {{ $this->commitSuggestion->branch }} · you commit — Majordom never does</p>
                            <textarea readonly rows="6" class="w-full rounded-md border border-border-soft bg-surface p-3 font-mono text-[12px] text-body">{{ $this->commitSuggestion->message }}</textarea>
                            <div x-data="{ open: false }">
                                <button type="button" @click="open = !open" class="cursor-pointer font-mono text-meta text-mute hover:text-t3">view diff</button>
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
                            <input type="text" wire:model="commitComment" placeholder="Comment (required for rework)…"
                                   class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body-sm text-hi placeholder:text-faint">
                            @error('commitComment') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
                            <div class="flex items-center gap-2">
                                <button wire:click="applyCommit" wire:confirm="Squash-merge {{ $this->commitSuggestion->branch }} into your current branch and commit?" wire:loading.attr="disabled"
                                        class="rounded-lg bg-accent px-3 py-1.5 text-body-sm font-semibold text-accent-ink disabled:opacity-55">
                                    <span wire:loading.remove wire:target="applyCommit">Merge into {{ $this->commitSuggestion->branch }}</span>
                                    <span wire:loading wire:target="applyCommit">Merging…</span>
                                </button>
                                <button wire:click="reworkCommit" wire:loading.attr="disabled"
                                        class="rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-semibold text-[#c7d2df] hover:bg-surface-active disabled:opacity-55">
                                    <span wire:loading.remove wire:target="reworkCommit">Rework</span>
                                    <span wire:loading wire:target="reworkCommit">Restarting…</span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endif

                @if($commitWarning !== null)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60">
                        <div class="max-w-[480px] w-full rounded-lg border border-failed-border bg-surface-raised p-5 space-y-3">
                            <div class="flex items-center gap-2">
                                <span class="text-failed-text text-lg">⚠</span>
                                <p class="font-mono text-micro uppercase tracking-[.14em] text-failed-text">CAN'T MERGE</p>
                            </div>
                            <p class="text-body-sm text-text">{{ $commitWarning }}</p>
                            <div class="flex justify-end">
                                <button wire:click="$set('commitWarning', null)" class="rounded-lg border border-border px-3 py-1.5 text-body-sm font-semibold text-mute hover:text-hi hover:bg-surface-active">OK</button>
                            </div>
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
                {{-- M16-B: one conversation, before and after the plan. The same
                     free chat reaches the Architect the whole way — a plain reply
                     to a proposal continues consensus; asking for a change lets the
                     Architect re-propose (no forced mode buttons). --}}
                <form wire:submit="send" class="flex gap-2">
                    <textarea wire:model="draft" rows="2" placeholder="{{ $this->planExists ? 'Ask a question, add a constraint, or request a change…' : 'Describe what to build…' }}" class="flex-1 rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint" @disabled($this->thinking)></textarea>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-semibold text-[#c7d2df] hover:bg-surface-active disabled:opacity-55" @disabled($this->thinking)>
                        <span wire:loading.remove wire:target="send">Send</span>
                        <span wire:loading wire:target="send">Sending…</span>
                    </button>
                </form>
                @error('draft') <p class="text-caption text-failed-text mt-1">{{ $message }}</p> @enderror
            </div>
        @elseif($tab === 'overview')
            @include('livewire.partials.project-overview')
        @elseif($tab === 'stats')
            @include('livewire.partials.project-stats')
        @elseif($tab === 'roadmap')
            @include('livewire.partials.project-roadmap')
        @elseif($tab === 'exchanges')
            @include('livewire.partials.project-exchanges')
        @elseif($tab === 'settings')
            @include('livewire.partials.project-settings')
        @endif
    </div>

    @if($tab === 'chat')
        <aside class="hidden w-[330px] shrink-0 flex-col border-l border-border lg:flex xl:w-[460px] 2xl:w-[640px]">
            <div class="flex items-center gap-2 px-4 py-4">
                <span class="font-mono text-micro uppercase tracking-[.14em] text-mute">Activity</span>
                <span class="h-2 w-2 rounded-full bg-status-working animate-led-pulse"></span>
                <span class="font-mono text-meta text-faint">live</span>
            </div>
            <div class="flex-1 overflow-y-auto">
                @forelse($timelineGroups as $group)
                    @php $targetSession = $group['key'] === 'consensus' ? 0 : ($executionSessionMap[$group['key']] ?? null); @endphp
                    <div x-data="{ open: {{ $group['is_current'] ? 'true' : 'false' }} }">
                        <button type="button"
                                @click="open = !open"
                                @if($targetSession !== null) onclick="window.dispatchEvent(new CustomEvent('open-session', { detail: { session: {{ $targetSession }} } }))" @endif
                                class="flex w-full items-center gap-2 border-b border-border-soft bg-surface-card px-4 py-2 text-left font-mono text-micro uppercase tracking-[.14em] text-mute transition-colors duration-120 hover:bg-surface-active hover:text-accent">
                            <span class="transition-transform duration-120" :class="open && 'rotate-90'">›</span>
                            <span>{{ $group['label'] }}</span>
                            @if(!$group['is_current'])
                                <span class="ml-auto normal-case tracking-normal text-faint">
                                    {{ $group['events']->first()?->name ?? 'idle' }}
                                </span>
                            @endif
                        </button>

                        <div x-show="open" x-cloak class="border-b border-border-soft">
                            @php
                                $answeredEvents = $group['events']->filter(fn($e) => str_contains($e->name, 'question') && (str_contains($e->name, 'answered') || str_contains($e->name, 'discarded')));
                                $otherEvents = $group['events']->diff($answeredEvents);
                            @endphp

                            @if($answeredEvents->isNotEmpty())
                                <div x-data="{ qOpen: false }">
                                    <button type="button" @click="qOpen = !qOpen" class="flex w-full items-center gap-2 px-4 py-2 text-left font-mono text-meta text-mute hover:bg-surface-active">
                                        <span class="transition-transform duration-120" :class="qOpen && 'rotate-90'">›</span>
                                        <span>{{ $answeredEvents->count() }} answered</span>
                                    </button>
                                    <div x-show="qOpen" x-cloak>
                                        @foreach($answeredEvents as $ev)
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
                                    </div>
                                </div>
                            @endif

                            @foreach($otherEvents as $ev)
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
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-6 font-mono text-meta text-faint">no activity yet</p>
                @endforelse
            </div>
        </aside>
    @endif

    @include('livewire.partials.approval-modal')
</div>

@script
<script>
    if (window.Echo) {
        window.Echo.channel('project.{{ $project->id }}')
            .listen('.domain-event', () => { $wire.dispatch('timeline-bump'); });
    }
</script>
@endscript
