<div class="mx-auto flex h-[calc(100vh-52px)] max-w-3xl flex-col px-6" @if($this->thinking) wire:poll.2s @endif>
    <div class="py-4 flex items-center gap-3 border-b border-border">
        <h1 class="text-title font-semibold text-hi">{{ $project->name }}</h1>
        <span class="rounded-[5px] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em]" style="background: var(--actor-architect-bg); color: var(--actor-architect)">Architect</span>
        <span class="font-mono text-meta text-mute">{{ config('majordom.architect.model') }}</span>
        @if($openCount > 0)
            <span class="ml-auto rounded-full border px-2.5 py-0.5 font-mono text-[10.5px] font-semibold tracking-[.06em] text-accent" style="border-color: var(--accent-border)">{{ $openCount }} question{{ $openCount > 1 ? 's' : '' }} remaining</span>
        @endif
    </div>

    <div class="flex-1 space-y-4 overflow-y-auto py-5">
        @forelse($messages as $message)
            @if($message->role === 'user')
                <div class="max-w-[640px] ml-auto">
                    <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">You</p>
                    <div class="mt-1 text-body text-body whitespace-pre-wrap">{{ $message->content }}</div>
                </div>
            @elseif($message->role === 'architect')
                <div class="max-w-[640px]">
                    <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">Architect</p>
                    <div class="mt-1 space-y-2 text-body text-body [&_p]:leading-relaxed [&_code]:font-mono [&_code]:text-[12px]">
                        {!! \Illuminate\Support\Str::markdown($message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </div>
                </div>
                @foreach($questionsByMessage[$message->id] ?? [] as $question)
                    @if($question->status === \App\Enums\QuestionStatus::Open)
                        <div class="max-w-[640px] rounded-lg border bg-surface-raised p-4 space-y-3" style="border-color: var(--accent-border)">
                            <p class="font-mono text-micro uppercase tracking-[.14em] text-accent">Open question</p>
                            <p class="text-body-sm text-text">{{ $question->text }}</p>
                            @if($question->options)
                                @foreach($question->options as $option)
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-md border border-border-strong bg-surface px-3 py-2 text-body-sm text-text">
                                        <input type="radio" wire:model="answerDrafts.{{ $question->id }}" value="{{ $option }}" class="accent-[#e2a33b]">
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                            @else
                                <input type="text" wire:model="answerDrafts.{{ $question->id }}" placeholder="Answer…" class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint">
                            @endif
                            @error("answer-{$question->id}") <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
                            <div class="flex items-center gap-3">
                                <button wire:click="answerQuestion({{ $question->id }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-body-sm font-semibold disabled:opacity-55" style="background: var(--accent); color: var(--accent-ink)">Answer</button>
                                <span class="font-mono text-meta text-faint">sends to Architect</span>
                            </div>
                        </div>
                    @else
                        <div class="max-w-[640px] flex items-center gap-2.5 rounded-lg border border-border-strong px-4 py-2.5 opacity-75">
                            <span class="text-ok">✓</span>
                            <p class="flex-1 truncate text-body-sm text-t3">{{ $question->text }}</p>
                            <span class="font-mono text-meta text-mute">answered</span>
                        </div>
                    @endif
                @endforeach
            @elseif($message->role === 'system')
                <p class="text-center font-mono text-meta text-mute">{{ $message->content }}</p>
            @endif
        @empty
            <div class="py-24 text-center space-y-3">
                <div class="inline-flex gap-2 justify-center">
                    <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                    <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                    <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                </div>
                <p class="text-display font-semibold text-hi">{{ $project->name }}</p>
                <p class="text-body text-t2">No memory yet. Describe the first feature to wake the Architect.</p>
            </div>
        @endforelse

        @if($this->thinking)
            <div class="flex items-center gap-2.5">
                <span class="h-2 w-2 rounded-full bg-status-working animate-led-pulse"></span>
                <span class="font-mono text-meta text-mute">architect is thinking…</span>
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
