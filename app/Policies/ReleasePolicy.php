<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Release;
use App\Models\User;

/**
 * Autorizzazione sulle release.
 *
 * L'oggetto della decisione sull'avvio e il **progetto**, non la release: al
 * momento del controllo la release non esiste ancora. Per questo `create` riceve
 * il progetto come secondo argomento.
 *
 * Il controllo vive qui, lato server: nascondere un comando nell'interfaccia non
 * e autorizzazione. Ogni ability usata deve esistere come metodo, perche il
 * filtro `before()` non viene invocato per ability senza metodo corrispondente.
 */
class ReleasePolicy
{
    /**
     * Ability i cui vincoli valgono anche per gli amministratori, e che quindi
     * devono essere decise dal proprio metodo invece che dal filtro.
     *
     * @var list<string>
     */
    private const NOT_FILTERED = ['delete'];

    /**
     * Un amministratore governa il processo di rilascio e puo intervenire su
     * qualsiasi release.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::NOT_FILTERED, true)) {
            return null;
        }

        return $user->isAdministrator() ? true : null;
    }

    /**
     * Consultare l'elenco delle release.
     *
     * Resta negato ai non amministratori: l'elenco con i filtri e la sua Policy
     * definitiva appartengono a US-009.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Consultare una singola release.
     *
     * Resta negato ai non amministratori: il dettaglio con valori e registro, e
     * l'apertura ai membri coinvolti, appartengono a US-008.
     */
    public function view(User $user, Release $release): bool
    {
        return false;
    }

    /**
     * Avviare una release su un progetto.
     *
     * Il progetto e l'oggetto della decisione perche la release non esiste
     * ancora. Le precondizioni di dominio — processo utilizzabile, ruoli coperti,
     * responsabili attivi — non sono autorizzazione e vivono in `StartRelease`.
     */
    public function create(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * La cancellazione delle release non e prevista per nessuno, amministratori
     * inclusi: una release **e** lo storico di un rilascio, e cancellarla
     * significa cancellare la prova di cosa e stato fatto e da chi. Il registro
     * delle transizioni non servirebbe a nulla se la riga a cui si riferisce
     * potesse sparire.
     */
    public function delete(User $user, Release $release): bool
    {
        return false;
    }
}
