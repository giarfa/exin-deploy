<?php

namespace App\Exceptions;

use App\Models\Project;
use RuntimeException;

/**
 * Un progetto disattivato non accoglie nuove release.
 *
 * Disattivare un progetto significa dichiarare che non ci si rilascia piu: il suo
 * storico resta leggibile, ma accettare una nuova release lo renderebbe una
 * disattivazione senza effetto.
 */
class InactiveProjectCannotStartRelease extends RuntimeException
{
    public static function for(Project $project): self
    {
        return new self(
            "Il progetto [{$project->name}] e disattivato e non accoglie nuove release."
        );
    }
}
