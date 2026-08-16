<?php

namespace App\Policies;

use App\Models\User;

/**
 * Autorizzazione sulla gestione dei membri del team.
 *
 * Il controllo vive qui, lato server: nascondere un comando nell'interfaccia non
 * e autorizzazione. Ogni ability usata deve esistere come metodo, perche il
 * filtro `before()` non viene invocato per ability senza metodo corrispondente.
 */
class UserPolicy
{
    /**
     * Ability i cui vincoli valgono anche per gli amministratori, e che quindi
     * devono essere decise dal proprio metodo invece che dal filtro.
     *
     * @var list<string>
     */
    private const NOT_FILTERED = ['toggleActivation', 'delete'];

    /**
     * Un amministratore puo compiere le normali operazioni sui membri.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::NOT_FILTERED, true)) {
            return null;
        }

        return $user->isAdministrator() ? true : null;
    }

    /**
     * Consultare l'elenco dei membri.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Consultare un singolo membro. Ognuno vede il proprio profilo.
     */
    public function view(User $user, User $member): bool
    {
        return $user->is($member);
    }

    /**
     * Creare un nuovo membro.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Modificare un membro.
     */
    public function update(User $user, User $member): bool
    {
        return false;
    }

    /**
     * Attivare o disattivare un membro.
     *
     * Vincolo valido anche per gli amministratori: nessuno puo disattivare se
     * stesso, altrimenti si escluderebbe dallo strumento senza che nessun altro
     * possa riattivarlo.
     */
    public function toggleActivation(User $user, User $member): bool
    {
        return $user->isAdministrator() && ! $user->is($member);
    }

    /**
     * La cancellazione dei membri non e prevista per nessuno, amministratori
     * inclusi: si disattivano, perche la loro traccia sui rilasci passati deve
     * restare leggibile nel registro (FR-016).
     */
    public function delete(User $user, User $member): bool
    {
        return false;
    }
}
