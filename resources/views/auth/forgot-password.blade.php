<x-layouts.auth :title="__('auth.forgot_heading') . ' — ' . __('auth.brand')">
    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg" level="1">{{ __('auth.forgot_heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('auth.forgot_description') }}</flux:text>
        </div>

        <x-auth.session-status />

        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf

            <flux:input
                type="email"
                name="email"
                :label="__('auth.email')"
                value="{{ old('email') }}"
                autocomplete="username"
                required
                autofocus
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('auth.forgot_action') }}
            </flux:button>
        </form>

        <flux:link href="{{ route('login') }}" class="text-sm">{{ __('auth.back_to_login') }}</flux:link>
    </flux:card>
</x-layouts.auth>
