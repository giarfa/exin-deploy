<?php

namespace App\Exceptions;

use App\Models\Project;
use App\Models\Role;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Non si avvia una release quando un ruolo previsto dal processo non ha un
 * responsabile sul progetto.
 *
 * Avviarla lo stesso produrrebbe uno step che nessuno puo chiudere, e il buco
 * emergerebbe solo quando la catena ci arriva — cioe nel momento peggiore. I
 * ruoli scoperti sono **nominati**: sapere che "manca un responsabile" senza
 * sapere quale non aiuta a risolverlo.
 */
class RolesWithoutResponsible extends RuntimeException
{
    /**
     * @param  list<string>  $roleNames
     * @param  list<string>  $roleIds  identificativi degli stessi ruoli, nello stesso ordine
     */
    private function __construct(
        string $message,
        public readonly array $roleNames,
        public readonly array $roleIds = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Gli identificativi affiancano i nomi — aggiunta additiva di US-013, che non
     * tocca il messaggio: la schermata di avvio ha un campo per ruolo, e senza
     * l'identificativo potrebbe solo mettere il rifiuto in testa invece che
     * accanto al selettore da correggere.
     *
     * @param  Collection<int, Role>  $roles
     */
    public static function on(Project $project, Collection $roles): self
    {
        $names = $roles->pluck('name')->all();

        return new self(
            "Sul progetto [{$project->name}] questi ruoli non hanno un responsabile: ".implode(', ', $names).'.',
            $names,
            $roles->pluck('id')->all(),
        );
    }
}
