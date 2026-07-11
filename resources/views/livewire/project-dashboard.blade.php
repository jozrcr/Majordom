<div class="mx-auto max-w-6xl px-7 py-6">
    <div class="flex items-baseline gap-4 mb-5">
        <h1 class="text-title font-semibold text-hi">Projects</h1>
        <span class="font-mono text-meta text-mute">{{ $summary }}</span>
    </div>

    @if ($projects->isEmpty() && !$showForm)
        <div class="py-24 text-center space-y-3">
            <div class="inline-flex gap-2 justify-center">
                <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                <span class="h-2 w-2 rounded-full bg-status-idle"></span>
                <span class="h-2 w-2 rounded-full bg-status-working animate-led-pulse"></span>
            </div>
            <p class="text-display font-semibold text-hi">No projects yet</p>
            <p class="text-body text-t2">Register a repository to wake the Architect.</p>
            <button wire:click="$set('showForm', true)" class="inline-flex rounded-xl border border-dashed border-border-strong p-4 text-body-sm text-faint hover:border-border-hover hover:text-t3 transition-colors duration-120 min-h-28 items-center justify-center px-6">
                + New project
            </button>
        </div>
    @else
        <div class="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-[18px]">
            @foreach ($projects as $project)
                @php
                    $cardClasses = match ($project->status) {
                        \App\Enums\ProjectStatus::NeedsYou => 'border-accent-border bg-accent-tint',
                        \App\Enums\ProjectStatus::Parked => 'border-failed-border bg-surface-card',
                        default => 'border-border bg-surface-card',
                    };
                    $ledClasses = match ($project->status) {
                        \App\Enums\ProjectStatus::Idle => 'bg-status-idle',
                        \App\Enums\ProjectStatus::Working => 'bg-status-working animate-led-pulse',
                        \App\Enums\ProjectStatus::NeedsYou => 'bg-accent led-glow animate-led-pulse',
                        \App\Enums\ProjectStatus::Parked => 'bg-status-failed',
                    };
                    $pillClasses = match ($project->status) {
                        \App\Enums\ProjectStatus::NeedsYou => 'bg-accent-tint text-accent',
                        \App\Enums\ProjectStatus::Parked => 'bg-failed-tint text-failed-text',
                        default => 'bg-surface-chip text-t3',
                    };
                @endphp
                <a href="{{ route('project.workspace', $project) }}" class="block">
                    <article class="rounded-xl border p-4 transition-colors duration-120 hover:border-border-hover hover:bg-surface-active {{ $cardClasses }}">
                        <div class="flex items-center gap-2.5">
                            <span class="h-2 w-2 rounded-full {{ $ledClasses }}"></span>
                            <h2 class="text-title-sm font-medium text-text">{{ $project->name }}</h2>
                            <span class="ml-auto rounded-full px-2.5 py-0.5 font-mono text-[10.5px] font-semibold tracking-[.06em] {{ $pillClasses }}">{{ $project->status->label() }}</span>
                        </div>
                        <p class="mt-3 text-body-sm text-t2">No milestones yet</p>
                        <p class="mt-2 font-mono text-meta text-mute truncate">{{ $project->repo_path }}</p>
                        <p class="mt-1 font-mono text-meta text-faint">{{ $project->last_activity_at?->diffForHumans() ?? '—' }}</p>
                    </article>
                </a>
            @endforeach

            @if (!$showForm)
                <button wire:click="$set('showForm', true)" class="rounded-xl border border-dashed border-border-strong p-4 text-body-sm text-faint hover:border-border-hover hover:text-t3 transition-colors duration-120 min-h-28 flex items-center justify-center">
                    + New project
                </button>
            @else
                <div class="rounded-xl border border-border-strong bg-surface-card p-4 space-y-3">
                    <p class="font-mono text-micro uppercase tracking-[.14em] text-mute">NEW PROJECT</p>
                    <input wire:model="name" type="text" placeholder="Project name" class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi placeholder:text-faint">
                    @error('name') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
                    <input wire:model="repoPath" type="text" placeholder="/absolute/path/to/repo" class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 font-mono text-body-sm text-hi placeholder:text-faint">
                    @error('repoPath') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button wire:click="createProject" wire:loading.attr="disabled" class="rounded-lg border border-border-hover px-3 py-1.5 text-body-sm font-semibold text-[#c7d2df] hover:bg-surface-active disabled:opacity-55">
                            <span wire:loading.remove wire:target="createProject">Register</span>
                            <span wire:loading wire:target="createProject">Registering…</span>
                        </button>
                        <button wire:click="$set('showForm', false)" class="rounded-lg px-3 py-1.5 text-body-sm text-t3 hover:text-hi">Cancel</button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
