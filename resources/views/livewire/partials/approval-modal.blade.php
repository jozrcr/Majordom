@php $approval = $this->openApproval; @endphp
@if($approval)
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
    <div class="max-w-[720px] w-full max-h-[85vh] overflow-auto rounded-lg border border-border bg-surface-raised p-5 space-y-3">
        @if($approval->type === \App\Enums\ApprovalType::HumanTask)
            <div class="flex items-center gap-2">
                <span class="font-mono text-micro uppercase tracking-[.14em] text-mute">Waiting: your task</span>
                <p class="text-body-sm text-text">{{ $approval->title }}</p>
            </div>
            <div class="font-mono text-meta text-mute">
                worktree: {{ $approval->payload['worktree'] ?? 'N/A' }}
            </div>
            <details class="text-caption text-t3">
                <summary class="cursor-pointer font-mono text-meta text-mute hover:text-t3">view brief</summary>
                <pre class="mt-2 max-h-[200px] overflow-auto rounded-md border border-border-soft bg-bg p-2 whitespace-pre-wrap">{{ $approval->payload['brief'] ?? '' }}</pre>
            </details>
            <input type="text" wire:model="gateComment" placeholder="Comment (required to skip)…" class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint">
            @error('gateComment') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
            <div class="flex items-center gap-3">
                <button wire:click="approveApproval" wire:loading.attr="disabled" class="rounded-lg bg-accent px-3 py-1.5 text-body-sm font-semibold text-accent-ink disabled:opacity-55">
                    <span wire:loading.remove wire:target="approveApproval">I'm done</span>
                    <span wire:loading wire:target="approveApproval">Submitting…</span>
                </button>
                <button wire:click="rejectApproval" wire:loading.attr="disabled" class="rounded-lg border border-failed-border px-3 py-1.5 text-body-sm font-semibold text-failed-text disabled:opacity-55 hover:bg-failed-tint">
                    <span wire:loading.remove wire:target="rejectApproval">Skip / park</span>
                    <span wire:loading wire:target="rejectApproval">Parking…</span>
                </button>
            </div>
        @elseif($approval->type === \App\Enums\ApprovalType::Review)
            <div class="flex items-center gap-2">
                <span class="font-mono text-micro uppercase tracking-[.14em] text-mute">Review gate</span>
                <p class="text-body-sm text-text">{{ $approval->title }}</p>
                <div class="ml-auto font-mono text-meta">
                    @if(($approval->payload['testsPassed'] ?? null) === true)
                        <span class="text-ok">tests ✓</span>
                    @elseif(($approval->payload['testsPassed'] ?? null) === false)
                        <span class="text-failed-text">tests ✗</span>
                    @else
                        <span class="text-mute">no tests</span>
                    @endif
                </div>
            </div>

            <p class="font-mono text-meta text-mute">{{ count($approval->payload['filesChanged'] ?? []) }} file(s) · builder: {{ config('majordom.builder.gateway_model') }}</p>

            <p class="text-body-sm text-t2">{{ $approval->payload['verdict']['summary'] ?? '' }}</p>
            @if(!empty($approval->payload['verdict']['comments']))
                <ul class="text-caption text-t3 list-disc pl-4 space-y-1">
                    @foreach($approval->payload['verdict']['comments'] as $comment)
                        {{-- Reviewer comments may be plain strings or {file, comment} objects. --}}
                        <li>
                            @if(is_array($comment))
                                @if(!empty($comment['file']))<span class="font-mono text-mute">{{ $comment['file'] }}:</span> @endif{{ $comment['comment'] ?? $comment['text'] ?? '' }}
                            @else
                                {{ $comment }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="max-h-[420px] overflow-auto rounded-md border border-border-soft bg-surface font-mono text-[12px] leading-[1.75]">
                @php
                    $diffLines = explode("\n", $approval->payload['diff'] ?? '');
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
                <button wire:click="approveApproval" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-body-sm font-semibold disabled:opacity-55">
                    <span wire:loading.remove wire:target="approveApproval">Approve</span>
                    <span wire:loading wire:target="approveApproval">Approving…</span>
                </button>
                <button wire:click="rejectApproval" wire:loading.attr="disabled" class="rounded-lg border px-3 py-1.5 text-body-sm font-semibold text-failed-text disabled:opacity-55 hover:bg-failed-tint">
                    <span wire:loading.remove wire:target="rejectApproval">Reject</span>
                    <span wire:loading wire:target="rejectApproval">Rejecting…</span>
                </button>
            </div>
        @elseif($approval->type === \App\Enums\ApprovalType::MilestoneMerge)
            @php $recap = $approval->payload['recap'] ?? []; $stat = $recap['diffstat'] ?? null; @endphp
            <div class="flex items-center gap-2">
                <span class="font-mono text-micro uppercase tracking-[.14em] text-ok">Milestone complete</span>
                <p class="text-body-sm text-text">{{ $approval->title }}</p>
            </div>

            @if(!empty($recap))
                {{-- Recap: everything the owner needs to decide "merge this?" --}}
                @if(!empty($recap['goal']))
                    <p class="text-body-sm text-t2">{{ $recap['goal'] }}</p>
                @endif

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-meta text-mute">
                    @if(!empty($recap['branch']))<span>branch: <span class="text-t3">{{ $recap['branch'] }}</span></span>@endif
                    @if($stat)
                        <span>· {{ $stat['files'] }} file(s)</span>
                        <span class="text-diff-add-text">+{{ $stat['insertions'] }}</span>
                        <span class="text-diff-del-text">−{{ $stat['deletions'] }}</span>
                    @endif
                </div>

                @if(!empty($recap['review_summary']))
                    <div class="rounded-md border border-border-soft bg-surface p-3">
                        <p class="font-mono text-micro uppercase tracking-[.14em] text-mute mb-1">Architect's verdict</p>
                        <p class="text-caption text-t2">{{ $recap['review_summary'] }}</p>
                    </div>
                @endif

                @if(!empty($recap['tasks']))
                    <details class="text-caption text-t3">
                        <summary class="cursor-pointer font-mono text-meta text-mute hover:text-t3">{{ count($recap['tasks']) }} task(s) &amp; acceptance criteria</summary>
                        <div class="mt-2 space-y-2">
                            @foreach($recap['tasks'] as $t)
                                <div class="rounded-md border border-border-soft bg-bg p-2">
                                    <p class="text-caption text-t2"><span class="font-mono text-mute">{{ $t['key'] ?? '' }}</span> {{ $t['title'] ?? '' }}</p>
                                    @if(!empty($t['criteria']))
                                        <pre class="mt-1 whitespace-pre-wrap text-micro text-t3">{{ $t['criteria'] }}</pre>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif

                @if(!empty($recap['how_to_test']))
                    <div class="rounded-md border border-accent-border bg-accent-tint p-3">
                        <p class="font-mono text-micro uppercase tracking-[.14em] text-accent mb-1">How to test it yourself</p>
                        <pre class="whitespace-pre-wrap text-caption text-t2">{{ $recap['how_to_test'] }}</pre>
                    </div>
                @endif

                {{-- View the full cumulative diff in-app, on demand. --}}
                <div>
                    <button wire:click="toggleMilestoneDiff" wire:loading.attr="disabled" class="font-mono text-meta text-mute hover:text-t3">
                        <span wire:loading.remove wire:target="toggleMilestoneDiff">{{ $showMilestoneDiff ? 'hide diff' : 'view diff' }}</span>
                        <span wire:loading wire:target="toggleMilestoneDiff">loading…</span>
                    </button>
                    @if($showMilestoneDiff)
                        <div class="mt-2 max-h-[420px] overflow-auto rounded-md border border-border-soft bg-surface font-mono text-[12px] leading-[1.75]">
                            @forelse(explode("\n", $milestoneDiff ?? '') as $line)
                                @php
                                    $cls = 'text-t3';
                                    if (str_starts_with($line, '+++') || str_starts_with($line, '---')) { $cls = 'text-t3'; }
                                    elseif (str_starts_with($line, '+')) { $cls = 'bg-diff-add-bg text-diff-add-text'; }
                                    elseif (str_starts_with($line, '-')) { $cls = 'bg-diff-del-bg text-diff-del-text'; }
                                    elseif (str_starts_with($line, '@@')) { $cls = 'bg-diff-hunk-bg text-diff-hunk-text'; }
                                    elseif (str_starts_with($line, 'diff --git')) { $cls = 'text-t2 font-semibold'; }
                                @endphp
                                <div class="whitespace-pre px-4 {{ $cls }}">{{ $line }}</div>
                            @empty
                                <div class="px-4 py-2 text-mute">(no diff)</div>
                            @endforelse
                        </div>
                    @endif
                </div>
            @endif

            <p class="font-mono text-meta text-mute">Merging promotes <span class="text-t3">{{ $recap['branch'] ?? "this milestone's branch" }}</span> into your checked-out branch. The next milestone starts automatically.</p>

            <input type="text" wire:model="gateComment" placeholder="What needs changing? (required to send back)…" class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint">
            @error('gateComment') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-3">
                <button wire:click="approveApproval" wire:loading.attr="disabled" class="rounded-lg bg-accent px-3 py-1.5 text-body-sm font-semibold text-accent-ink disabled:opacity-55">
                    <span wire:loading.remove wire:target="approveApproval">Merge into main &amp; start next</span>
                    <span wire:loading wire:target="approveApproval">Merging…</span>
                </button>
                <button wire:click="requestGateChanges" wire:loading.attr="disabled" class="rounded-lg border border-failed-border px-3 py-1.5 text-body-sm font-semibold text-failed-text disabled:opacity-55 hover:bg-failed-tint">
                    <span wire:loading.remove wire:target="requestGateChanges">Send back to the Architect</span>
                    <span wire:loading wire:target="requestGateChanges">Sending…</span>
                </button>
                <button wire:click="deferMilestone" wire:loading.attr="disabled" class="rounded-lg border border-border px-3 py-1.5 text-body-sm font-semibold text-mute disabled:opacity-55 hover:text-hi">
                    <span wire:loading.remove wire:target="deferMilestone">Not yet — keep it ready</span>
                    <span wire:loading wire:target="deferMilestone">…</span>
                </button>
            </div>
        @endif
    </div>
</div>
@endif
