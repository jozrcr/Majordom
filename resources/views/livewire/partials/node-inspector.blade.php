@php $inspectedNode = $this->inspectedNode; @endphp
@if($inspectedNode)
<div class="border-b border-border bg-surface-card px-4 py-3 space-y-3">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="font-mono text-sm font-semibold text-hi">{{ $inspectedNode->type }}</span>
            @php
                $status = $inspectedNode->status->value;
                $statusClass = 'bg-status-idle border-border-soft text-mute';
                if ($status === 'completed') $statusClass = 'bg-surface-active border-ok/40 text-ok';
                elseif ($status === 'running') $statusClass = 'bg-working-tint border-status-working text-status-working animate-pulse';
                elseif ($status === 'failed') $statusClass = 'bg-failed-tint border-failed-border text-failed-text';
                elseif ($status === 'waiting_human') $statusClass = 'bg-accent-tint border-accent text-accent';
            @endphp
            <span class="rounded-lg border px-2 py-0.5 text-[10px] font-mono {{ $statusClass }}">{{ $inspectedNode->status->label() }}</span>
            @php
                $duration = '—';
                if ($inspectedNode->started_at && $inspectedNode->finished_at) {
                    $diff = $inspectedNode->started_at->diffInSeconds($inspectedNode->finished_at);
                    $mins = intdiv($diff, 60);
                    $secs = $diff % 60;
                    $duration = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
                } elseif ($inspectedNode->started_at) {
                    $duration = 'running…';
                }
            @endphp
            <span class="font-mono text-meta text-mute">{{ $duration }}</span>
        </div>
        <button type="button" wire:click="inspectNode({{ $inspectedNode->id }})" class="text-mute hover:text-hi transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>

    @if($inspectedNode->status === \App\Enums\NodeStatus::Failed)
        @php
            $errorDetail = $inspectedNode->output['error'] ?? $inspectedNode->output['message'] ?? 'no detail recorded';
        @endphp
        <div class="rounded-md border border-failed-border bg-failed-tint p-3">
            <p class="font-mono text-sm text-failed-text">{{ $errorDetail }}</p>
        </div>
    @endif

    @if(!empty($inspectedNode->input))
        <div>
            <p class="font-mono text-micro uppercase tracking-[.14em] text-mute mb-1">Input</p>
            <div class="rounded-md border border-border-soft bg-surface p-3 space-y-2">
                @if(isset($inspectedNode->input['role']))
                    <div class="flex gap-2 text-body-sm">
                        <span class="text-mute">Role:</span>
                        <span class="font-mono text-t3">{{ $inspectedNode->input['role'] }}</span>
                    </div>
                @endif
                @if(isset($inspectedNode->input['config']) && is_array($inspectedNode->input['config']))
                    <div class="text-body-sm">
                        <span class="text-mute">Config:</span>
                        <ul class="list-disc pl-4 mt-1 space-y-0.5 text-t3">
                            @foreach($inspectedNode->input['config'] as $ck => $cv)
                                <li><span class="font-mono text-mute">{{ $ck }}:</span> @if(is_array($cv))<span class="text-mute text-xs">(nested — see raw)</span>@else{{ is_bool($cv) ? ($cv ? 'yes' : 'no') : $cv }}@endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @foreach($inspectedNode->input as $k => $v)
                    @if($k !== 'role' && $k !== 'config')
                        <div class="flex gap-2 text-body-sm">
                            <span class="text-mute">{{ $k }}:</span>
                            <span x-data="{ open: false }" class="text-t3">
                                @if(is_array($v))
                                    <span class="text-mute text-xs">(nested — see raw)</span>
                                @elseif(is_string($v) && mb_strlen($v) > 600)
                                    <span x-show="!open">{{ mb_substr($v, 0, 600) }}…</span>
                                    <span x-show="open">{{ $v }}</span>
                                    <button type="button" x-on:click="open = !open" x-text="open ? 'show less' : 'show more'" class="text-accent underline text-xs ml-1">show more</button>
                                @else
                                    {{ is_bool($v) ? ($v ? 'yes' : 'no') : $v }}
                                @endif
                            </span>
                        </div>
                    @endif
                @endforeach
            </div>
            <details class="mt-2">
                <summary class="cursor-pointer font-mono text-meta text-mute hover:text-t3">View raw</summary>
                <pre class="mt-2 max-h-[240px] overflow-auto rounded-md border border-border-soft bg-surface p-3 font-mono text-[11px] leading-relaxed text-t3">{{ json_encode($inspectedNode->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </div>
    @endif

    @if(!empty($inspectedNode->output))
        <div>
            <p class="font-mono text-micro uppercase tracking-[.14em] text-mute mb-1">Output</p>
            <div class="rounded-md border border-border-soft bg-surface p-3 space-y-3">
                @if($inspectedNode->type === 'review')
                    @php
                        $verdict = $inspectedNode->output['verdict'] ?? [];
                        $summary = $verdict['summary'] ?? '';
                        $comments = $verdict['comments'] ?? [];
                        $questions = $inspectedNode->output['questions'] ?? [];
                        $reason = $inspectedNode->output['reason'] ?? '';
                    @endphp
                    @if($summary)
                        <p class="text-body-sm text-t3">{{ $summary }}</p>
                    @endif
                    @if(!empty($comments))
                        <div>
                            <p class="font-mono text-meta text-mute mb-1">Reviewer comments</p>
                            <ul class="list-disc pl-4 space-y-1 text-body-sm text-t3">
                                @foreach($comments as $comment)
                                    <li>
                                        @if(is_array($comment))
                                            @if(!empty($comment['file']))<span class="font-mono text-mute">{{ $comment['file'] }}:</span> @endif{{ $comment['comment'] ?? $comment['text'] ?? '' }}
                                        @else
                                            {{ $comment }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if(!empty($questions))
                        <div>
                            <p class="font-mono text-meta text-mute mb-1">Questions</p>
                            <ul class="list-disc pl-4 space-y-1 text-body-sm text-t3">
                                @foreach($questions as $q)
                                    <li>{{ $q }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if($reason)
                        <p class="text-body-sm text-failed-text">{{ $reason }}</p>
                    @endif

                @elseif($inspectedNode->type === 'build')
                    @php
                        $bSummary = $inspectedNode->output['summary'] ?? '';
                        $bFiles = $inspectedNode->output['filesChanged'] ?? [];
                        $bTests = $inspectedNode->output['testsPassed'] ?? null;
                        $bDiff = $inspectedNode->output['diff'] ?? '';
                    @endphp
                    @if($bSummary)
                        <p class="text-body-sm text-t3">{{ $bSummary }}</p>
                    @endif
                    @if(!empty($bFiles))
                        <div>
                            <p class="font-mono text-meta text-mute mb-1">Files changed</p>
                            <ul class="list-disc pl-4 space-y-0.5 text-body-sm">
                                @foreach($bFiles as $f)
                                    <li class="font-mono text-t3">{{ $f }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="text-body-sm">
                        <span class="text-mute">Tests:</span>
                        @if($bTests === true)
                            <span class="text-ok">passed</span>
                        @elseif($bTests === false)
                            <span class="text-failed-text">failed</span>
                        @else
                            <span class="text-mute">—</span>
                        @endif
                    </div>
                    @if($bDiff !== '')
                        <details class="mt-2">
                            <summary class="cursor-pointer font-mono text-meta text-mute hover:text-t3">Diff</summary>
                            <div class="mt-2 max-h-[320px] overflow-auto rounded-md border border-border-soft bg-surface font-mono text-[12px] leading-[1.75]">
                                @foreach(explode("\n", $bDiff) as $line)
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
                        </details>
                    @endif

                @else
                    {{-- Fallback for unknown types --}}
                    @foreach($inspectedNode->output as $k => $v)
                        <div class="flex gap-2 text-body-sm">
                            <span class="text-mute">{{ $k }}:</span>
                            <span x-data="{ open: false }" class="text-t3">
                                @if(is_array($v))
                                    @if(count($v) && is_string($v[array_keys($v)[0]] ?? ''))
                                        <ul class="list-disc pl-4 space-y-0.5">
                                            @foreach($v as $_val)
                                                <li>{{ $_val }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <span class="text-mute text-xs">(nested array — see raw)</span>
                                    @endif
                                @elseif(is_string($v) && mb_strlen($v) > 600)
                                    <span x-show="!open">{{ mb_substr($v, 0, 600) }}…</span>
                                    <span x-show="open">{{ $v }}</span>
                                    <button type="button" x-on:click="open = !open" x-text="open ? 'show less' : 'show more'" class="text-accent underline text-xs ml-1">show more</button>
                                @else
                                    {{ is_bool($v) ? ($v ? 'yes' : 'no') : $v }}
                                @endif
                            </span>
                        </div>
                    @endforeach
                @endif
            </div>
            <details class="mt-2">
                <summary class="cursor-pointer font-mono text-meta text-mute hover:text-t3">View raw</summary>
                <pre class="mt-2 max-h-[240px] overflow-auto rounded-md border border-border-soft bg-surface p-3 font-mono text-[11px] leading-relaxed text-t3">{{ json_encode($inspectedNode->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </div>
    @endif
</div>
@endif
