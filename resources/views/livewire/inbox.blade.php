<div class="mx-auto max-w-4xl px-7 py-6">
    <div wire:poll.5s class="hidden"></div>

    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <h1 class="text-title font-semibold text-hi">Needs you</h1>
            @if($count > 0)
                <span class="rounded-full px-2 py-0.5 font-mono text-[10.5px] font-semibold bg-accent-tint text-accent">{{ $count }}</span>
            @endif
        </div>
        <select wire:model.live="projectFilter" class="ml-auto rounded-lg border border-border-strong bg-surface px-2 py-1.5 text-body-sm text-hi">
            <option value="">all projects</option>
            @foreach($projects as $project)
                <option value="{{ $project->id }}">{{ $project->name }}</option>
            @endforeach
        </select>
    </div>

    @if($items->isNotEmpty())
        <div class="divide-y divide-border-soft">
            @foreach($items as $item)
                <div class="flex items-center gap-4 px-2 py-3 hover:bg-surface transition-colors duration-120">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-accent led-glow animate-led-pulse"></span>
                    <span class="w-[118px] shrink-0 font-mono text-[10px] font-semibold uppercase tracking-[.1em] {{ $item['type'] === 'question' ? 'text-accent' : 'text-mute' }}">{{ $item['label'] }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="text-body-sm text-text truncate">{{ $item['title'] }}</div>
                        <div class="text-meta text-mute">{{ $item['project']->name }} · {{ $item['at']->diffForHumans() }}</div>
                    </div>
                    <a href="{{ route('project.workspace', $item['project']) }}" class="shrink-0 rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-semibold text-[#c7d2df] hover:bg-surface-active">{{ $item['action'] }}</a>
                </div>
            @endforeach
        </div>
    @else
        <div class="py-24 text-center space-y-3">
            <div class="flex justify-center gap-2">
                <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                <span class="h-2 w-2 rounded-full bg-status-working animate-led-pulse"></span>
            </div>
            <p class="text-display font-semibold text-hi">All quiet</p>
            <p class="text-body text-t2">Nothing needs you. The estate runs itself tonight.</p>
        </div>
    @endif
</div>
