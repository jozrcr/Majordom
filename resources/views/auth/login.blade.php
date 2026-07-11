<x-layouts.app>
    <x-slot:title>Majordom — sign in</x-slot:title>

    <div class="min-h-screen bg-bg flex items-center justify-center">
        <form method="POST" action="{{ route('login.attempt') }}" class="w-80 space-y-4 rounded-xl border border-border bg-surface-card p-6">
            @csrf
            <div>
                <label for="token" class="font-mono text-micro uppercase tracking-[.14em] text-mute">Token</label>
                <input type="password" name="token" id="token" class="w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-body text-hi" required autofocus>
            </div>
            @if($errors->has('token'))
                <p class="text-failed-text text-caption">{{ $errors->first('token') }}</p>
            @endif
            <button type="submit" class="w-full rounded-lg border border-border-hover px-3 py-2 text-body-sm font-semibold text-[#c7d2df]">Enter</button>
        </form>
    </div>
</x-layouts.app>
