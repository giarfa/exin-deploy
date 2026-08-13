<?php

use App\Models\Release;
use App\Models\ReleaseEvent;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Registro delle transizioni di una release: cosa e successo, per mano di chi e
 * quando.
 *
 * Schermata di **sola lettura**, e qui non e una scelta di prodotto ma la sostanza
 * della cosa: un registro che si potesse modificare non sarebbe una prova. Il
 * rifiuto e presidiato due volte — `ReleaseEventPolicy` nega `update` e `delete` a
 * chiunque, amministratori inclusi, e `ReleaseEvent` solleva
 * `ReleaseEventIsAppendOnly` su ogni scrittura che passi da un modello.
 *
 * Alcune righe non sono per tutti: i **tentativi non autorizzati** nominano una
 * persona e cosa ha provato a fare, e restano ai soli amministratori. Il filtro
 * vive in query (`ReleaseEvent::visibleTo`) e non in memoria, perche il numero
 * delle righe nascoste e a sua volta informazione.
 */
new class extends Component
{
    /** Release risolta dal binding di rotta. */
    public Release $release;

    public function mount(): void
    {
        // Secondo livello dopo il middleware, come su tutte le altre schermate.
        Gate::authorize('viewAny', ReleaseEvent::class);
    }
};
?>

<div>
    <flux:heading size="xl" level="1">{{ __('releases.log_heading') }}</flux:heading>
</div>
