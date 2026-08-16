<x-layouts.auth :title="__('auth.confirm_heading') . ' — ' . __('auth.brand')">
    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg" level="1">{{ __('auth.confirm_heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('auth.confirm_description') }}</flux:text>
        </div>

        <x-auth.session-status />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
            @csrf

            <flux:input
                type="password"
                name="password"
                :label="__('auth.password_label')"
                autocomplete="current-password"
                required
                viewable
                autofocus
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('auth.confirm_action') }}
            </flux:button>
        </form>
    </flux:card>
</x-layouts.auth>
