<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Autorizzazione sul catalogo dei ruoli funzionali.
 *
 * Il controllo vive qui, lato server: nascondere un comando nell'interfaccia non
 * e autorizzazione. Ogni ability usata deve esistere come metodo, perche il
 * filtro `before()` non viene invocato per ability senza metodo corrispondente.
 */
class RolePolicy
{
    /**
     * Ability i cui vincoli valgono anche per gli amministratori, e che quindi
     * devono essere decise dal proprio metodo invece che dal filtro.
     *
     * @var list<string>
     */
    private const NOT_FILTERED = ['delete'];

    /**
     * Un amministratore configura il processo di rilascio.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::NOT_FILTERED, true)) {
            return null;
        }

        return $user->isAdministrator() ? true : null;
    }

    /**
     * Consultare il catalogo dei ruoli.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Consultare un singolo ruolo.
     */
    public function view(User $user, Role $role): bool
    {
        return false;
    }

    /**
     * Creare un ruolo funzionale.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Modificare nome e descrizione di un ruolo.
     */
    public function update(User $user, Role $role): bool
    {
        return false;
    }

    /**
     * Attivare o disattivare un ruolo.
     */
    public function toggleActivation(User $user, Role $role): bool
    {
        return false;
    }

    /**
     * Cancellare un ruolo.
     *
     * Vincolo valido anche per gli amministratori: un ruolo referenziato da una
     * mappatura, da un template o da una release non e cancellabile, perche la sua
     * sparizione renderebbe illeggibile cio che vi si appoggia. Resta disattivabile.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->isAdministrator() && ! $role->isReferenced();
    }
}
