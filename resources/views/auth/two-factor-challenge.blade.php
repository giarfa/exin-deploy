<x-layouts.auth :title="__('auth.two_factor_heading') . ' — ' . __('auth.brand')">
    <flux:card class="space-y-6" x-data="{ recovery: false }">
        <div>
            <flux:heading size="lg" level="1">{{ __('auth.two_factor_heading') }}</flux:heading>
            <flux:text class="mt-1" x-show="! recovery">{{ __('auth.two_factor_description') }}</flux:text>
        </div>

        <x-auth.session-status />

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5">
            @csrf

            <div x-show="! recovery">
                <flux:input
                    name="code"
                    :label="__('auth.two_factor_code')"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    x-bind:autofocus="! recovery"
                />
            </div>

            <div x-show="recovery" x-cloak>
                <flux:input
                    name="recovery_code"
                    :label="__('auth.two_factor_recovery_code')"
                    autocomplete="one-time-code"
                />
            </div>

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('auth.two_factor_action') }}
            </flux:button>
        </form>

        <flux:link
            as="button"
            type="button"
            class="text-sm"
            x-on:click="recovery = ! recovery"
            x-text="recovery ? @js(__('auth.two_factor_use_code')) : @js(__('auth.two_factor_use_recovery'))"
        />
    </flux:card>
</x-layouts.auth>
