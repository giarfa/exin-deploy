<?php

namespace App\Exceptions;

use App\Models\Project;
use App\Models\Role;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Non si avvia una release quando un ruolo previsto dal processo non ha un
 * responsabile.
 *
 * Avviarla lo stesso produrrebbe uno step che nessuno puo chiudere, e il buco
 * emergerebbe solo quando la catena ci arriva — cioe nel momento peggiore. I
 * ruoli scoperti sono **nominati**: sapere che "manca un responsabile" senza
 * sapere quale non aiuta a risolverlo.
 *
 * L'elenco e calcolato sulla mappatura **effettiva**, cioe al netto degli
 * override indicati in fase di creazione (US-013): un ruolo senza responsabile
 * di progetto ma coperto da un override valido non compare qui. Nomina quindi i
 * soli ruoli davvero da risolvere, e non quelli che chi avvia ha appena risolto.
 */
class RolesWithoutResponsible extends RuntimeException
{
    /**
     * @param  list<string>  $roleNames
     */
    private function __construct(string $message, public readonly array $roleNames)
    {
        parent::__construct($message);
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    public static function on(Project $project, Collection $roles): self
    {
        $names = $roles->pluck('name')->all();

        return new self(
            "Sul progetto [{$project->name}] questi ruoli non hanno un responsabile: ".implode(', ', $names).'.',
            $names
        );
    }
}
