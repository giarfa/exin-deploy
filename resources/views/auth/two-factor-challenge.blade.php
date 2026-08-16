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

        {{-- Due elementi alternati invece di un `x-text`: l'etichetta contiene un
             apice ("Usa il codice dell'app di autenticazione") e ogni forma che la
             porta dentro una stringa JavaScript deve inseguirne l'escaping — con
             `@js()` fuori gioco, perche nelle attribute bag dei componenti Blade
             le direttive non vengono compilate (vedi `.ai/rules/views.md`). Qui la
             stringa JavaScript non esiste: Alpine sceglie quale span mostrare.

             `x-cloak` sul secondo, come sui due blocchi di campo sopra: prima
             dell'avvio di Alpine deve comparire una sola etichetta, quella dello
             stato iniziale. --}}
        <flux:link
            as="button"
            type="button"
            class="text-sm"
            x-on:click="recovery = ! recovery"
        >
            <span x-show="! recovery">{{ __('auth.two_factor_use_recovery') }}</span>
            <span x-show="recovery" x-cloak>{{ __('auth.two_factor_use_code') }}</span>
        </flux:link>
    </flux:card>
</x-layouts.auth>
