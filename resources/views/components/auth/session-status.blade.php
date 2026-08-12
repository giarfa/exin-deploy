{{--
    Esito di un'operazione di autenticazione: errori di validazione e messaggi di
    sessione. `role="alert"` perche il contenuto compare dopo un tentativo e deve
    essere annunciato agli screen reader senza attendere una nuova navigazione.
--}}

@if (session('status'))
    <flux:callout variant="success" icon="check-circle" role="alert">
        <flux:callout.text>{{ session('status') }}</flux:callout.text>
    </flux:callout>
@endif

@if ($errors->any())
    <flux:callout variant="danger" icon="exclamation-triangle" role="alert">
        <flux:callout.text>
            @if ($errors->count() === 1)
                {{ $errors->first() }}
            @else
                <ul class="list-disc space-y-1 ps-4">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </flux:callout.text>
    </flux:callout>
@endif
