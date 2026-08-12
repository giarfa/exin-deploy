<?php

namespace App\Actions\Workflows;

use App\Exceptions\InactiveTemplateCannotBeDefault;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Imposta il template predefinito, quello proposto alla creazione di un progetto.
 *
 * E l'**unico percorso di scrittura** del flag `is_default`, ed e il motivo per
 * cui l'invariante "un solo predefinito" regge senza un vincolo di schema: un
 * indice unico parziale non e portabile fra SQLite, MySQL e PostgreSQL con le
 * migrazioni di Laravel, e la variante con colonna nullable renderebbe
 * `is_default` ambigua (`null` invece di `false`) proprio dove il codice la legge
 * come booleana.
 *
 * Contropartite dichiarate: percorso unico, transazione, e un test che verifica
 * l'assenza di due predefiniti dopo una sequenza di operazioni. Se in futuro il
 * flag venisse scritto da un secondo percorso, l'indice parziale va rivalutato.
 */
class SetDefaultWorkflowTemplate
{
    /**
     * @throws InactiveTemplateCannotBeDefault se il template e disattivato
     */
    public function handle(WorkflowTemplate $template): void
    {
        if (! $template->is_active) {
            throw InactiveTemplateCannotBeDefault::for($template);
        }

        DB::transaction(function () use ($template): void {
            // Una sola scrittura di massa per l'azzeramento: mai un ciclo di
            // update riga per riga.
            WorkflowTemplate::query()
                ->whereKeyNot($template->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $template->update(['is_default' => true]);
        });
    }
}
