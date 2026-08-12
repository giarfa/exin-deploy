@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    $enabled = ! is_null($user->two_factor_secret);
    $confirmed = ! is_null($user->two_factor_confirmed_at);
@endphp

<x-layouts.app :title="__('app.two_factor_heading')">
    <div class="mx-auto max-w-xl space-y-6">
        <div>
            <flux:heading size="xl" level="1">{{ __('app.security_heading') }}</flux:heading>
            <flux:text class="mt-2">{{ __('app.two_factor_intro') }}</flux:text>
        </div>

        <x-auth.session-status />

        <flux:card class="space-y-5">
            <flux:heading size="lg" level="2">{{ __('app.two_factor_heading') }}</flux:heading>

            @if (! $enabled)
                <flux:text>{{ __('app.two_factor_disabled') }}</flux:text>

                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <flux:button type="submit" variant="primary">{{ __('app.two_factor_enable') }}</flux:button>
                </form>
            @elseif (! $confirmed)
                <flux:text>{{ __('app.two_factor_scan') }}</flux:text>

                <div class="rounded-lg bg-white p-4 dark:bg-zinc-100 [&_svg]:h-40 [&_svg]:w-40">
                    {!! $user->twoFactorQrCodeSvg() !!}
                </div>

                <div>
                    <flux:heading size="sm" level="3">{{ __('app.two_factor_secret') }}</flux:heading>
                    <code class="mt-1 block break-all font-mono text-sm">{{ decrypt($user->two_factor_secret) }}</code>
                </div>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-4">
                    @csrf

                    <flux:input
                        name="code"
                        :label="__('auth.two_factor_code')"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        required
                        autofocus
                    />

                    <flux:button type="submit" variant="primary">{{ __('app.two_factor_confirm') }}</flux:button>
                </form>
            @else
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.text>{{ __('app.two_factor_active') }}</flux:callout.text>
                </flux:callout>

                <div>
                    <flux:heading size="sm" level="3">{{ __('app.two_factor_recovery_heading') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('app.two_factor_recovery_intro') }}</flux:text>

                    <ul class="mt-3 space-y-1 rounded-lg bg-zinc-50 p-4 font-mono text-sm dark:bg-zinc-800">
                        @foreach (json_decode(decrypt($user->two_factor_recovery_codes), true) as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                        @csrf
                        <flux:button type="submit" variant="outline">
                            {{ __('app.two_factor_recovery_regenerate') }}
                        </flux:button>
                    </form>

                    <form method="POST" action="{{ route('two-factor.disable') }}">
                        @csrf
                        @method('DELETE')
                        <flux:button type="submit" variant="danger">
                            {{ __('app.two_factor_disable') }}
                        </flux:button>
                    </form>
                </div>
            @endif
        </flux:card>

        <flux:link href="{{ route('home') }}" class="text-sm">{{ __('app.home_heading') }}</flux:link>
    </div>
</x-layouts.app>
