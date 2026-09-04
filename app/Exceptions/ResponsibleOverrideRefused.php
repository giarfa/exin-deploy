<?php

namespace App\Exceptions;

use App\Models\Project;
use RuntimeException;

/**
 * Non si avvia una release quando un responsabile **indicato in avvio** non e
 * ammissibile.
 *
 * E distinta da `RolesWithoutResponsible` e da `InactiveResponsibleOnProject`
 * perche parla della scelta appena fatta e non della configurazione del progetto:
 * si risolve cambiando la persona nel modulo, non aggiornando la mappatura.
 *
 * Due costruttori, perche i due casi si risolvono in modi diversi:
 *
 * - `notAssignable()` — la persona indicata per uno o piu ruoli non esiste o e
 *   disattivata. Ogni motivo e **indicizzato per ruolo**, cosi che la schermata
 *   possa agganciare l'errore al campo che lo ha generato.
 * - `roleNotInProcess()` — il payload nomina un ruolo che il processo del
 *   progetto non prevede. Non e un errore di campo: nessun campo con quel nome
 *   esiste nel modulo, quindi la schermata non avrebbe dove agganciarlo. E un
 *   payload manipolato, e il messaggio va in testa.
 */
class ResponsibleOverrideRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $reasonsByRole  identificativo del ruolo => chiave di traduzione del motivo; **vuoto** quando il rifiuto non ha un campo a cui agganciarsi
     * @param  array<string, string>  $roleNames  identificativo del ruolo => nome del ruolo, per i messaggi
     * @param  string|null  $headKey  chiave del messaggio in testa; valorizzata **solo** da `roleNotInProcess()`
     */
    private function __construct(
        string $message,
        public readonly array $reasonsByRole,
        public readonly array $roleNames,
        public readonly ?string $headKey = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Una o piu persone indicate in avvio non esistono o sono disattivate.
     *
     * @param  array<string, string>  $reasonsByRole  identificativo del ruolo => chiave di traduzione
     * @param  array<string, string>  $roleNames  identificativo del ruolo => nome del ruolo
     */
    public static function notAssignable(Project $project, array $reasonsByRole, array $roleNames): self
    {
        $named = array_map(
            fn (string $roleId): string => $roleNames[$roleId] ?? $roleId,
            array_keys($reasonsByRole)
        );

        return new self(
            "Sul progetto [{$project->name}] i responsabili indicati in avvio per questi ruoli non sono ammissibili: ".implode(', ', $named).'.',
            $reasonsByRole,
            $roleNames,
        );
    }

    /**
     * Il payload nomina ruoli che il processo del progetto non prevede.
     *
     * @param  list<string>  $roleIds  identificativi arrivati dal client e non previsti
     */
    public static function roleNotInProcess(Project $project, array $roleIds): self
    {
        return new self(
            "Sul progetto [{$project->name}] questi ruoli non sono previsti dal processo: ".implode(', ', $roleIds).'.',
            [],
            [],
            'releases.override_refused_role_not_in_process',
        );
    }
}
