<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Majordom' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-bg antialiased">
    {{-- Global nav — handoff §2.14: 52px bar, mono brand, quiet links.
         The inbox badge is the only persistent accent in chrome; hidden at zero. --}}
    <nav class="flex h-[52px] items-center gap-6 border-b border-border px-6">
        <a href="/" class="font-mono text-[12px] font-medium tracking-[.18em] text-hi">MAJORDOM</a>
        <div class="flex items-center gap-5 text-body-sm">
            <a href="/" class="font-medium text-hi">Projects</a>
            @php $inboxCount = \App\Livewire\Inbox::openCount(); @endphp
            <a href="{{ route('inbox') }}" class="flex items-center gap-1.5 text-t3 transition-colors duration-120 hover:text-hi">
                Inbox
                @if($inboxCount > 0)
                    <span class="rounded-full px-1.5 py-0.5 font-mono text-[10.5px] font-semibold leading-none bg-accent text-accent-ink">{{ $inboxCount }}</span>
                @endif
            </a>
            <a href="{{ route('settings') }}" class="text-t3 transition-colors duration-120 hover:text-hi">Settings</a>
        </div>
        <div class="ml-auto flex items-center gap-4">
            <span class="h-[26px] w-[26px] rounded-full border border-border-strong bg-surface-chip"></span>
        </div>
    </nav>

    <main>
        {{ $slot }}
    </main>
</body>
</html>
