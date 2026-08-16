<?php

namespace App\Policies;

use App\Enums\ReleaseEventAction;
use App\Models\ReleaseEvent;
use App\Models\User;

/**
 * Autorizzazione sul registro delle transizioni.
 *
 * L'oggetto della decisione e l'**evento** e non la release: la visibilita si
 * decide riga per riga, perche una voce di tentativo non autorizzato non e
 * informazione di processo ma di sicurezza. Appendere queste ability a
 * `ReleasePolicy` le nasconderebbe dietro un'entita che non e quella su cui si
 * decide.
 *
 * `update` e `delete` non sono una difesa ridondante rispetto a
 * `App\Exceptions\ReleaseEventIsAppendOnly`: il modello rifiuta le scritture che
 * passano da lui, la Policy nega l'**intenzione** prima che una schermata possa
 * offrirla. Il vincolo permanente 10 del README dice cosa nessuno dei due copre —
 * le scritture di massa del query builder, che non attraversano gli eventi
 * Eloquent — e perche chiudere anche quelle richiederebbe un trigger di database,
 * incompatibile con il vincolo di portabilita.
 */
class ReleaseEventPolicy
{
    /**
     * Ability i cui vincoli valgono anche per gli amministratori, e che quindi
     * devono essere decise dal proprio metodo invece che dal filtro.
     *
     * @var list<string>
     */
    private const NOT_FILTERED = ['update', 'delete'];

    /**
     * Un amministratore governa il processo di rilascio, ma non il registro di cio
     * che e gia successo.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::NOT_FILTERED, true)) {
            return null;
        }

        return $user->isAdministrator() ? true : null;
    }

    /**
     * Consultare il registro di una release.
     *
     * Aperta a ogni utente autenticato, come la lettura della release stessa: la
     * tracciabilita del processo esiste per essere consultata, e un registro
     * visibile solo agli amministratori sarebbe una prova che nessuno degli
     * interessati puo verificare.
     *
     * Quali **righe** si vedono e deciso da `view()`, non da qui.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Consultare una singola voce.
     *
     * Le transizioni di processo — avvio, chiusura, attivazione, conclusione —
     * sono visibili a chiunque. I **tentativi non autorizzati** no: quella riga
     * nomina una persona e cosa ha provato a fare, ed e materiale di sicurezza che
     * riguarda chi presidia lo strumento, non l'intero team. Mostrarla a tutti
     * trasformerebbe il registro in una lavagna delle colpe.
     *
     * Il filtro corrispondente in lettura vive su `ReleaseEvent::visibleTo()`, ed e
     * la **stessa decisione** espressa in query: caricare tutte le righe per
     * scartarle qui costerebbe una lettura di dati che chi guarda non puo vedere, e
     * il loro numero e a sua volta informazione. Le due devono restare allineate —
     * un test le confronta riga per riga sullo stesso insieme.
     */
    public function view(User $user, ReleaseEvent $releaseEvent): bool
    {
        return $releaseEvent->action !== ReleaseEventAction::UnauthorizedAttempt
            || $user->isAdministrator();
    }

    /**
     * Il registro e in sola aggiunta: una voce scritta non si modifica, e la
     * garanzia vale **anche** per un amministratore. Un registro correggibile a
     * posteriori non e una prova, e il potere di correggerlo renderebbe sospetta
     * ogni riga anche quando nessuno lo ha usato.
     */
    public function update(User $user, ReleaseEvent $releaseEvent): bool
    {
        return false;
    }

    /**
     * Nemmeno la cancellazione, per la stessa ragione e con la stessa portata:
     * negata a chiunque, amministratori inclusi.
     */
    public function delete(User $user, ReleaseEvent $releaseEvent): bool
    {
        return false;
    }
}
