<x-layouts.app :title="__('app.home_heading')">
    <div class="mx-auto max-w-2xl">
        <flux:heading size="xl" level="1">{{ __('app.home_heading') }}, {{ auth()->user()->name }}</flux:heading>

        <flux:text class="mt-2">{{ __('app.home_placeholder') }}</flux:text>

        <flux:separator class="my-6" />

        <flux:link href="{{ route('settings.two-factor') }}">{{ __('app.security_heading') }}</flux:link>
    </div>
</x-layouts.app>
