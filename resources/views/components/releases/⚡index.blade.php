<?php

use App\Models\Release;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Elenco delle release, in corso e concluse.
 *
 * Schermata di **sola lettura** come il dettaglio: nessuna azione, nessun form.
 * Risponde alla domanda d'insieme — quali rilasci sono aperti, su chi sono fermi,
 * cosa e stato consegnato e quando — mentre il dettaglio risponde su un rilascio
 * solo.
 *
 * Aperta a ogni membro autenticato (`ReleasePolicy::viewAny`), con la doppia
 * protezione consueta: middleware sulla rotta e Gate al montaggio.
 */
new class extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', Release::class);
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('releases.index_heading') }}</flux:heading>
    </div>
</div>
