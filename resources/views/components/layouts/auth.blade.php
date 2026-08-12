<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark:scheme-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Strumento interno: mai indicizzato, nemmeno se raggiungibile per errore. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('auth.brand') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-dvh bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
    <a href="#contenuto"
       class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-zinc-900 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white dark:focus:bg-white dark:focus:text-zinc-900">
        {{ __('auth.skip_to_content') }}
    </a>

    <main id="contenuto" class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center gap-3">
                <img src="/images/brand/logo.svg" alt="{{ __('auth.brand') }}" width="150" height="29"
                     class="h-7 w-auto text-zinc-900 dark:text-white">
                <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('auth.tagline') }}</p>
            </div>

            {{ $slot }}
        </div>
    </main>

    @fluxScripts
</body>
</html>
