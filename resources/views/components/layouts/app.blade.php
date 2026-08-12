@props(['title' => null, 'breadcrumbs' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark:scheme-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Strumento interno: mai indicizzato, nemmeno se raggiungibile per errore. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' — '.__('auth.brand') : __('auth.brand') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-dvh bg-white text-zinc-800 antialiased dark:bg-zinc-800 dark:text-white">
    <a href="#contenuto"
       class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-zinc-900 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white dark:focus:bg-white dark:focus:text-zinc-900">
        {{ __('auth.skip_to_content') }}
    </a>

    {{--
        Sidebar permanente da 1024 px, drawer sovrapposto sotto: e la soglia
        `max-lg:` gestita da Flux, unica in tutta l'applicazione.
    --}}
    <flux:sidebar sticky collapsible="mobile"
                  class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" inset="left" />

        <a href="{{ route('home') }}" class="mb-2 flex items-center px-1 py-2" aria-label="{{ __('auth.brand') }}">
            <img src="/images/brand/logo.svg" alt="{{ __('auth.brand') }}" width="140" height="27"
                 class="h-6 w-auto text-zinc-800 dark:text-white">
        </a>

        <flux:navlist variant="outline">
            <flux:navlist.group :heading="__('app.nav_operational')">
                <flux:navlist.item icon="inbox-arrow-down" :href="route('home')" :current="request()->routeIs('home')">
                    {{ __('app.nav_my_steps') }}
                </flux:navlist.item>

                {{-- Voci non ancora implementate: disabilitate, non nascoste, perche la
                     struttura del prodotto deve essere leggibile da subito. --}}
                <x-nav.planned icon="rocket-launch">{{ __('app.nav_releases') }}</x-nav.planned>
                <x-nav.planned icon="folder">{{ __('app.nav_projects') }}</x-nav.planned>
            </flux:navlist.group>

            @can('viewAny', App\Models\User::class)
                <flux:navlist.group :heading="__('app.nav_configuration')">
                    <x-nav.planned icon="queue-list">{{ __('app.nav_templates') }}</x-nav.planned>
                    <x-nav.planned icon="identification">{{ __('app.nav_roles') }}</x-nav.planned>

                    <flux:navlist.item icon="users" :href="route('members.index')"
                                       :current="request()->routeIs('members.*')">
                        {{ __('app.nav_members') }}
                    </flux:navlist.item>
                </flux:navlist.group>
            @endcan
        </flux:navlist>

        <flux:spacer />

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()" />

            <flux:menu>
                <flux:menu.item :href="route('settings.two-factor')" icon="shield-check">
                    {{ __('app.security_heading') }}
                </flux:menu.item>

                <flux:menu.separator />

                <flux:menu.radio.group x-data x-model="$flux.appearance">
                    <flux:menu.radio value="light" icon="sun">{{ __('app.theme_light') }}</flux:menu.radio>
                    <flux:menu.radio value="dark" icon="moon">{{ __('app.theme_dark') }}</flux:menu.radio>
                    <flux:menu.radio value="system" icon="computer-desktop">{{ __('app.theme_system') }}</flux:menu.radio>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('app.logout') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="bottom" align="end">
            <flux:profile :initials="auth()->user()->initials()" :chevron="false" />

            <flux:menu>
                <flux:menu.item :href="route('settings.two-factor')" icon="shield-check">
                    {{ __('app.security_heading') }}
                </flux:menu.item>

                <flux:menu.separator />

                <flux:menu.radio.group x-data x-model="$flux.appearance">
                    <flux:menu.radio value="light" icon="sun">{{ __('app.theme_light') }}</flux:menu.radio>
                    <flux:menu.radio value="dark" icon="moon">{{ __('app.theme_dark') }}</flux:menu.radio>
                    <flux:menu.radio value="system" icon="computer-desktop">{{ __('app.theme_system') }}</flux:menu.radio>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('app.logout') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main id="contenuto">
        @if ($breadcrumbs)
            <flux:breadcrumbs class="mb-5">{{ $breadcrumbs }}</flux:breadcrumbs>
        @endif

        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
