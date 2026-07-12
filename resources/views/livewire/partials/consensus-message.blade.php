@if($message->role === \App\Enums\MessageRole::User)
    <div id="msg-{{ $message->id }}" class="ml-auto w-fit max-w-[640px] rounded-lg border border-border-soft bg-surface px-4 py-3">
        <p class="text-right font-mono text-micro uppercase tracking-[.14em] text-mute">You</p>
        <div class="mt-1 text-body text-body whitespace-pre-wrap">{{ $message->content }}</div>
    </div>
@elseif($message->role === \App\Enums\MessageRole::Architect)
    <div id="msg-{{ $message->id }}" class="max-w-[640px]">
        <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">Architect</p>
        <div class="mt-1 space-y-2 text-body text-body [&_p]:leading-relaxed [&_code]:font-mono [&_code]:text-[12px]">
            {!! \Illuminate\Support\Str::markdown($message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </div>
    </div>
    @foreach($questionsByMessage[$message->id] ?? [] as $question)
        @if($question->status === \App\Enums\QuestionStatus::Open)
            <div class="max-w-[640px] rounded-lg border bg-surface-raised p-4 space-y-3">
                <p class="font-mono text-micro uppercase tracking-[.14em] text-accent">Open question</p>
                <p class="text-body-sm text-text">{{ $question->text }}</p>
                @if($question->options)
                    @foreach($question->options as $option)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-md border border-border-strong bg-surface px-3 py-2 text-body-sm text-text">
                            <input type="radio" wire:model="answerDrafts.{{ $question->id }}" value="{{ $option }}" class="accent-[#e2a33b]">
                            <span>{{ $option }}</span>
                        </label>
                    @endforeach
                @endif
                <input type="text" wire:model="customDrafts.{{ $question->id }}"
                       placeholder="{{ $question->options ? 'Or answer in your own words — “your call” works too…' : 'Answer…' }}"
                       class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint">
                @error("answer-{$question->id}") <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
                <div class="flex items-center gap-3">
                    <button wire:click="answerQuestion({{ $question->id }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-body-sm font-semibold disabled:opacity-55">Answer</button>
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
@elseif($message->role === \App\Enums\MessageRole::System)
    <p id="msg-{{ $message->id }}" class="text-center font-mono text-meta text-mute">{{ $message->content }}</p>
@endif
