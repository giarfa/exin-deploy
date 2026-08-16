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
     * Concessa a **ogni utente autenticato**, ora che la schermata che la usa
     * esiste. La decisione era rinviata di proposito — un'autorizzazione senza una
     * pagina che la applichi non si sa valutare — e cade dalla stessa parte di
     * `view()`, per la stessa ragione: lo strumento non invia notifiche (rischio
     * accettato n.1 del PRD), quindi vedere quali rilasci sono in corso e su chi si
     * sono fermati e la funzione del prodotto, non un privilegio. Un elenco riservato
     * agli amministratori obbligherebbe chiunque altro a conoscere in anticipo
     * l'indirizzo del rilascio che cerca.
     *
     * Non e un allineamento automatico delle due ability: e l'elenco a somigliare al
     * dettaglio, perche mostra le stesse cose in fila. Cosa **resta chiuso**:
     * `create` — avviare una release e degli amministratori, concessa dal filtro
     * `before()` e da nessun altro percorso — e `delete`, negata a chiunque per
     * sempre.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Consultare una singola release.
     *
     * Concessa a **ogni utente autenticato**, anche a chi non e responsabile di
     * alcuno step di quella release: sapere dove e fermo un rilascio non e un
     * privilegio, e la funzione stessa dello strumento. Lo strumento non invia
     * notifiche (rischio accettato n.1 del PRD), quindi chiunque debba sollecitare
     * deve poter vedere su chi il flusso si e fermato e da quanto.
     *
     * Cosa **resta chiuso**, e non per dimenticanza: `delete`, negata a chiunque per
     * sempre. `viewAny` e invece aperta anch'essa da US-009, quando la schermata che
     * la applica e arrivata.
     *
     * Il dettaglio e in **sola lettura**: compilare e chiudere restano decise da
     * `ReleaseStepPolicy`, che concede solo al responsabile dello step attivo o a
     * un amministratore.
     */
    public function view(User $user, Release $release): bool
    {
        return true;
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
