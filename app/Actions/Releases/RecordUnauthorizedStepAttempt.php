<?php

namespace App\Actions\Releases;

use App\Enums\ReleaseEventAction;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Registra un tentativo non autorizzato su uno step, nel registro delle transizioni
 * **e** nel log applicativo.
 *
 * Sono due destinazioni distinte con due lettori distinti, come prescrive il PRD
 * (`### Sicurezza`), e una sola non basta: il **log** serve a chi presidia il
 * sistema e vive con la rotazione dei file; il **registro** e una prova di
 * processo, in sola aggiunta, che resta accanto al rilascio a cui si riferisce.
 * Chi indaga un rilascio contestato guarda il secondo, chi indaga un attacco
 * guarda il primo.
 *
 * **Solo i tentativi mutanti** (`fill`, `close`) entrano nel registro. Un `view`
 * negato produce la voce di log e si ferma li: il registro non e cancellabile
 * (`ReleaseEventIsAppendOnly`), quindi basterebbe qualcuno che ricarica un
 * indirizzo per gonfiarlo di righe che non dicono nulla su come e andato il
 * rilascio. FR-012 parla di compilare e chiudere, ed e quello che va tracciato.
 *
 * Nessuna transazione, e deliberato: la registrazione non deve poter essere
 * annullata dal rifiuto che l'ha causata. Chi chiama scrive prima e rifiuta dopo.
 */
class RecordUnauthorizedStepAttempt
{
    /**
     * Ability il cui rifiuto merita una riga nel registro: quelle che avrebbero
     * cambiato lo stato del rilascio.
     *
     * @var list<string>
     */
    private const RECORDED_ABILITIES = ['fill', 'close'];

    public function handle(ReleaseStep $step, User $actor, string $ability): void
    {
        // Il log riceve **ogni** tentativo, con contesto strutturato: e la sede in
        // cui un ripetersi anomalo si nota.
        Log::warning('Tentativo non autorizzato su uno step di release.', [
            'ability' => $ability,
            'release_id' => $step->release_id,
            'release_step_id' => $step->id,
            'step_position' => $step->position,
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
        ]);

        if (! in_array($ability, self::RECORDED_ABILITIES, true)) {
            return;
        }

        ReleaseEvent::create([
            'release_id' => $step->release_id,
            'release_step_id' => $step->id,
            'user_id' => $actor->id,
            'action' => ReleaseEventAction::UnauthorizedAttempt,
            'payload' => [
                'ability' => $ability,
                'position' => $step->position,
                'step' => $step->name,
            ],
        ]);
    }
}
