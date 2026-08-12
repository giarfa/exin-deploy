<x-layouts.auth :title="__('auth.reset_heading') . ' — ' . __('auth.brand')">
    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg" level="1">{{ __('auth.reset_heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('auth.reset_description') }}</flux:text>
        </div>

        <x-auth.session-status />

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <flux:input
                type="email"
                name="email"
                :label="__('auth.email')"
                value="{{ old('email', $request->email) }}"
                autocomplete="username"
                required
            />

            <flux:input
                type="password"
                name="password"
                :label="__('auth.password_label')"
                :description="__('auth.password_requirements')"
                autocomplete="new-password"
                required
                viewable
                autofocus
            />

            <flux:input
                type="password"
                name="password_confirmation"
                :label="__('auth.password_confirmation')"
                autocomplete="new-password"
                required
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('auth.reset_action') }}
            </flux:button>
        </form>
    </flux:card>
</x-layouts.auth>
