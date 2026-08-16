<?php

namespace App\Exceptions;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Non si avvia una release quando un responsabile risolto dalla mappatura del
 * progetto e disattivato.
 *
 * Un membro disattivato non accede piu: lo step gli verrebbe assegnato e
 * resterebbe fermo. La mappatura resta valida per lo storico, ma per una nuova
 * release va aggiornata prima.
 *
 * Le persone sono **nominate**, per lo stesso motivo dei ruoli scoperti.
 */
class InactiveResponsibleOnProject extends RuntimeException
{
    /**
     * @param  list<string>  $memberNames
     */
    private function __construct(string $message, public readonly array $memberNames)
    {
        parent::__construct($message);
    }

    /**
     * @param  Collection<int, User>  $members
     */
    public static function on(Project $project, Collection $members): self
    {
        $names = $members->pluck('name')->all();

        return new self(
            "Sul progetto [{$project->name}] questi responsabili sono disattivati: ".implode(', ', $names).'.',
            $names
        );
    }
}
