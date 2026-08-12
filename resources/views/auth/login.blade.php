<x-layouts.auth :title="__('auth.login_heading') . ' — ' . __('auth.brand')">
    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg" level="1">{{ __('auth.login_heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('auth.login_description') }}</flux:text>
        </div>

        <x-auth.session-status />

        <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
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

            <flux:input
                type="password"
                name="password"
                :label="__('auth.password_label')"
                autocomplete="current-password"
                required
                viewable
            />

            <div class="flex items-center justify-between gap-4">
                <flux:checkbox name="remember" :label="__('auth.remember_me')" />

                <flux:link href="{{ route('password.request') }}" class="text-sm">
                    {{ __('auth.forgot_password') }}
                </flux:link>
            </div>

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('auth.login_action') }}
            </flux:button>
        </form>
    </flux:card>

    <p class="mt-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('auth.no_public_registration') }}
    </p>
</x-layouts.auth>
