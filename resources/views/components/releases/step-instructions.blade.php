@props(['step', 'next' => null])

{{--
    Istruzioni congelate dello step, e la dichiarazione di **a chi passa il flusso**
    alla chiusura.

    La seconda riga non e un dettaglio: lo strumento non invia notifiche (rischio
    accettato n.1 del PRD), quindi chi chiude deve sapere chi avvisare a voce. Senza
    questa frase, il flusso si fermerebbe in silenzio sul responsabile successivo,
    che non ha modo di sapere che e il suo turno.

    Le istruzioni arrivano dallo snapshot: sono quelle che il template chiedeva
    all'avvio, non quelle di adesso.
--}}
<div class="space-y-2 rounded-lg border-s-2 border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:heading size="lg" level="3">{{ __('releases.step_instructions_heading') }}</flux:heading>

    @if ($step->instructions)
        <flux:text class="text-sm whitespace-pre-line">{{ $step->instructions }}</flux:text>
    @endif

    @if ($step->status === App\Enums\ReleaseStepStatus::Active)
        <flux:text class="text-sm font-medium">
            @if ($next)
                {{ __('releases.step_hands_over_to', [
                    'name' => $next->assignedUser->name,
                    'step' => $next->name,
                ]) }}
            @else
                {{ __('releases.step_hands_over_last') }}
            @endif
        </flux:text>
    @endif
</div>
