<?php

namespace App\Policies;

use App\Models\DefaultRoleAssignment;
use App\Models\User;

/**
 * Autorizzazione sulla mappatura predefinita ruolo -> persona del team.
 *
 * E la sorgente da cui i nuovi progetti vengono precompilati: modificarla e
 * un'operazione di configurazione, riservata agli amministratori.
 */
class DefaultRoleAssignmentPolicy
{
    /**
     * Un amministratore configura il processo di rilascio.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdministrator() ? true : null;
    }

    /**
     * Consultare la mappatura predefinita.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Definire la persona predefinita di un ruolo.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Sostituire la persona predefinita di un ruolo.
     */
    public function update(User $user, DefaultRoleAssignment $assignment): bool
    {
        return false;
    }

    /**
     * Rimuovere la persona predefinita di un ruolo.
     *
     * Cancellabile senza vincoli: e una preferenza di configurazione, non una
     * traccia storica. I progetti gia creati conservano la propria mappatura.
     */
    public function delete(User $user, DefaultRoleAssignment $assignment): bool
    {
        return false;
    }
}
