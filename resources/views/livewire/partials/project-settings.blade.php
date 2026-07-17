<div class="flex flex-col gap-6 p-6 max-w-2xl">
    <h2 class="text-title font-semibold text-hi">Settings</h2>

    @if(session('settings_ok'))
        <div class="rounded border border-ok bg-ok-tint px-3 py-2 text-body-sm text-ok">
            {{ session('settings_ok') }}
        </div>
    @endif

    {{-- 1. Rename --}}
    <div class="flex flex-col gap-1.5">
        <label class="text-sm text-t3">Rename</label>
        <div class="flex gap-2">
            <input type="text" wire:model="settingsName" class="flex-1 rounded border border-border bg-surface px-3 py-1.5 text-body text-hi placeholder:text-faint">
            <button wire:click="renameProject" class="rounded border border-border-hover px-3 py-1.5 text-body-sm font-medium text-hi hover:bg-surface-active">Save</button>
        </div>
        @error('settingsName') <p class="text-caption text-failed-text">{{ $message }}</p> @enderror
    </div>

    {{-- 2. Archive/Unarchive --}}
    <div class="flex flex-col gap-1.5">
        <label class="text-sm text-t3">Archive</label>
        <button wire:click="toggleArchive" class="rounded border border-border-hover px-3 py-1.5 text-body-sm font-medium text-hi hover:bg-surface-active">
            {{ $project->archived_at ? 'Unarchive project' : 'Archive project' }}
        </button>
    </div>

    {{-- 3. Autonomy profile --}}
    <div class="flex flex-col gap-1.5">
        <label class="text-sm text-t3">Autonomy profile</label>
        <div class="flex flex-wrap gap-2">
            @foreach(['attended', 'overnight', 'full_auto'] as $profile)
                <button wire:click="switchProfile('{{ $profile }}')"
                        class="rounded border px-3 py-1.5 text-body-sm font-medium transition-colors {{ $this->buildProfile === $profile ? 'border-accent bg-accent-tint text-accent' : 'border-border text-t3 hover:bg-surface-active' }}">
                    {{ ucfirst($profile) }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- 4. confirm_commits --}}
    <div class="flex items-center justify-between">
        <label class="text-sm text-t3">Confirm commits</label>
        <input type="checkbox" wire:click="toggleConfirmCommits" {{ $project->confirm_commits ? 'checked' : '' }} class="rounded border-border bg-surface text-accent focus:ring-accent">
    </div>

    {{-- 5. push_after_merge --}}
    <div class="flex items-center justify-between">
        <label class="text-sm text-t3">Push after merge <span class="text-faint">(global)</span></label>
        <input type="checkbox" wire:click="togglePushAfterMerge" {{ \App\Support\Setting::get('git.push_after_merge', false) ? 'checked' : '' }} class="rounded border-border bg-surface text-accent focus:ring-accent">
    </div>

    {{-- 6. night_mode --}}
    <div class="flex flex-col gap-1.5">
        <div class="flex items-center justify-between">
            <label class="text-sm text-t3">Night mode</label>
            <input type="checkbox" disabled class="rounded border-border bg-surface text-accent opacity-50 cursor-not-allowed">
        </div>
        <p class="text-caption text-faint">Coming in M14</p>
    </div>
</div>
