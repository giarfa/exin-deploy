<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Autorizzazione sui progetti e sulla loro mappatura ruolo -> persona.
 *
 * Le assegnazioni di progetto non hanno una Policy propria: non esistono fuori dal
 * contesto di un progetto, quindi sono decise da `manageAssignments`.
 */
class ProjectPolicy
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
     * Consultare l'elenco dei progetti.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Consultare un singolo progetto.
     */
    public function view(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Creare un progetto.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Modificare nome, slug e descrizione di un progetto.
     */
    public function update(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Attivare o disattivare un progetto.
     */
    public function toggleActivation(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Definire chi ricopre ciascun ruolo sul progetto.
     */
    public function manageAssignments(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * La cancellazione dei progetti non e prevista per nessuno, amministratori
     * inclusi: un progetto e il contenitore dello storico dei suoi rilasci, che e
     * il valore che lo strumento accumula nel tempo. Si disattiva.
     */
    public function delete(User $user, Project $project): bool
    {
        return false;
    }
}
