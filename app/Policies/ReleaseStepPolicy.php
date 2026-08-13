<?php

namespace App\Policies;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\ReleaseStep;
use App\Models\User;

/**
 * Autorizzazione su un singolo step di una release avviata.
 *
 * Il controllo vive qui, lato server: nascondere il comando nell'interfaccia non e
 * autorizzazione, e le azioni Livewire non ripassano dal middleware della rotta.
 *
 * `fill` e `close` non sono decise dal filtro `before()`: il vincolo dello step
 * **attivo** vale anche per un amministratore. Un amministratore governa il
 * processo, ma nemmeno lui compila uno step il cui turno non e arrivato o che e
 * gia chiuso — quello non sarebbe un privilegio, sarebbe la catena che smette di
 * descrivere l'ordine in cui il rilascio e avvenuto. Stessa forma di
 * `ReleasePolicy::delete()`.
 */
class ReleaseStepPolicy
{
    /**
     * Ability i cui vincoli valgono anche per gli amministratori, e che quindi
     * devono essere decise dal proprio metodo invece che dal filtro.
     *
     * @var list<string>
     */
    private const NOT_FILTERED = ['fill', 'close'];

    /**
     * Un amministratore governa il processo di rilascio e vede qualsiasi step.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::NOT_FILTERED, true)) {
            return null;
        }

        return $user->isAdministrator() ? true : null;
    }

    /**
     * Consultare uno step: il suo responsabile, in qualunque stato lo step si
     * trovi.
     *
     * Anche da chiuso o bloccato: chi ne risponde deve poter leggere cosa gli e
     * stato chiesto e cosa ha fornito. L'apertura del dettaglio a tutti i membri
     * coinvolti nella release e US-008.
     */
    public function view(User $user, ReleaseStep $step): bool
    {
        return $this->isResponsible($user, $step);
    }

    /**
     * Compilare i campi dello step senza chiuderlo.
     *
     * Una bozza su uno step bloccato o completato non ha significato: il primo non
     * e ancora suo turno, il secondo e in sola lettura.
     */
    public function fill(User $user, ReleaseStep $step): bool
    {
        return $this->mayAct($user, $step);
    }

    /**
     * Chiudere lo step e passare il flusso al responsabile successivo.
     *
     * Le precondizioni di dominio oltre lo stato — valori validi, esistenza di uno
     * step successivo — non sono autorizzazione e vivono in
     * `App\Actions\Releases\CloseStep`.
     */
    public function close(User $user, ReleaseStep $step): bool
    {
        return $this->mayAct($user, $step);
    }

    /**
     * Chi puo agire sullo step: il responsabile assegnato o un amministratore, e
     * solo mentre lo step e attivo su una release in corso.
     *
     * `loadMissing` e non `release`: la schermata carica la release in eager
     * loading, e senza questo la Policy pagherebbe una query per ogni controllo —
     * uno al montaggio e uno per azione.
     */
    private function mayAct(User $user, ReleaseStep $step): bool
    {
        if (! $this->isResponsible($user, $step)) {
            return false;
        }

        if ($step->status !== ReleaseStepStatus::Active) {
            return false;
        }

        $step->loadMissing('release');

        return $step->release->status === ReleaseStatus::InProgress;
    }

    /**
     * Il responsabile assegnato allo step, oppure un amministratore.
     */
    private function isResponsible(User $user, ReleaseStep $step): bool
    {
        return $step->assigned_user_id === $user->id || $user->isAdministrator();
    }
}
