<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowTemplate;

/**
 * Autorizzazione sui template di workflow, sui loro step e sui campi richiesti.
 *
 * Step e campi non hanno una Policy propria: non esistono fuori dal contesto di
 * un template, quindi sono decisi da `manageSteps` — come le assegnazioni di
 * progetto sono decise da `manageAssignments`.
 *
 * Il controllo vive qui, lato server: nascondere un comando nell'interfaccia non
 * e autorizzazione. Ogni ability usata deve esistere come metodo, perche il
 * filtro `before()` non viene invocato per ability senza metodo corrispondente.
 */
class WorkflowTemplatePolicy
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
     * Consultare l'elenco dei template.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Consultare un singolo template.
     */
    public function view(User $user, WorkflowTemplate $template): bool
    {
        return false;
    }

    /**
     * Creare un template di workflow.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Modificare nome e descrizione di un template.
     */
    public function update(User $user, WorkflowTemplate $template): bool
    {
        return false;
    }

    /**
     * Attivare o disattivare un template.
     */
    public function toggleActivation(User $user, WorkflowTemplate $template): bool
    {
        return false;
    }

    /**
     * Eleggere il template proposto alla creazione di un nuovo progetto.
     */
    public function setDefault(User $user, WorkflowTemplate $template): bool
    {
        return false;
    }

    /**
     * Definire gli step del template e i campi richiesti da ciascuno.
     */
    public function manageSteps(User $user, WorkflowTemplate $template): bool
    {
        return false;
    }

    /**
     * La cancellazione dei template non e prevista per nessuno, amministratori
     * inclusi: un template e la forma di processo a cui si appoggiano progetti e
     * release gia avviate. Si disattiva.
     */
    public function delete(User $user, WorkflowTemplate $template): bool
    {
        return false;
    }
}
