<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark:scheme-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Strumento interno: mai indicizzato, nemmeno se raggiungibile per errore. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title . ' — ' . __('auth.brand') : __('auth.brand') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-dvh bg-white text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
    <a href="#contenuto"
       class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-zinc-900 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white dark:focus:bg-white dark:focus:text-zinc-900">
        {{ __('auth.skip_to_content') }}
    </a>

    {{-- La shell completa con sidebar e drawer arriva in TASK-07: qui il guscio minimo. --}}
    <header class="flex items-center gap-4 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
        <a href="{{ route('home') }}" class="flex items-center" aria-label="{{ __('auth.brand') }}">
            <img src="/images/brand/logo.svg" alt="{{ __('auth.brand') }}" width="130" height="25"
                 class="h-6 w-auto text-zinc-900 dark:text-white">
        </a>

        <div class="ms-auto flex items-center gap-3">
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ auth()->user()?->name }}</span>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm">{{ __('app.logout') }}</flux:button>
            </form>
        </div>
    </header>

    <main id="contenuto" class="px-4 py-6">
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
