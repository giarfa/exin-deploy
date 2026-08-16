<?php

namespace App\Actions\Releases;

use App\Enums\ReleaseEventAction;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\StepAlreadyClosed;
use App\Exceptions\StepIsNotOpen;
use App\Exceptions\StepValuesAreInvalid;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Chiude uno step compilato e passa il flusso al responsabile successivo, oppure
 * conclude la release quando lo step chiuso e l'ultimo della catena.
 *
 * E l'**unico percorso di avanzamento**. Chiusura dello step, attivazione del
 * successivo — o conclusione della release — e scrittura degli eventi avvengono in
 * **una sola transazione**, come impone `.ai/rules/app.md`: se una qualsiasi di
 * quelle scritture fallisse da sola, la release resterebbe in uno stato che nessuna
 * schermata sa raccontare — uno step chiuso e nessuno attivo, oppure due attivi,
 * oppure una release conclusa con l'ultimo step ancora aperto.
 *
 * Legge **solo lo snapshot**: nessuna query su `step_definitions`,
 * `field_definitions`, `workflow_templates` o `project_role_assignments`.
 * Riordinare o modificare un template non deve cambiare come avanza una release
 * gia avviata (verificato da `SnapshotIsolationTest`).
 *
 * L'ordine dei rifiuti e quello in cui si risolvono: prima lo stato — che non
 * dipende da chi compila — e solo dopo i valori. Validare per primo farebbe
 * correggere un form che comunque non si sarebbe potuto chiudere.
 */
class CloseStep
{
    /**
     * @param  array<string, mixed>  $values  valori compilati, indicizzati per identificativo di campo
     *
     * @throws StepIsNotOpen se la release e conclusa, o lo step e bloccato o gia completato
     * @throws StepValuesAreInvalid se i valori forniti non soddisfano cio che lo step chiede
     * @throws StepAlreadyClosed se un altro invio ha chiuso lo step — o concluso la release — per primo
     */
    public function handle(ReleaseStep $step, array $values, User $actor): ReleaseStep
    {
        return DB::transaction(function () use ($step, $values, $actor): ReleaseStep {
            /*
             * Lock pessimistico sulla riga della release, come prescrive
             * `.ai/rules/app.md`, **piu** un update condizionato piu sotto. I due
             * non sono ridondanti, e togliere il secondo perche "c'e il lock"
             * romperebbe proprio l'ambiente su cui il prodotto gira:
             *
             * su SQLite `lockForUpdate()` non produce SQL — la grammatica non
             * supporta `FOR UPDATE` — quindi qui la garanzia effettiva e il
             * compare-and-swap, che e l'alternativa prevista dall'invariante 3 del
             * PRD. Su MySQL e PostgreSQL il lock serializza davvero le due
             * transazioni, e il vincolo di portabilita (`.ai/rules/general.md`)
             * impone che il codice resti corretto su tutti e tre i motori.
             *
             * Senza questo commento, una revisione futura toglierebbe quella che
             * sul proprio motore sembra la ridondanza inutile.
             */
            $release = Release::query()
                ->whereKey($step->release_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($release->status !== ReleaseStatus::InProgress) {
                throw StepIsNotOpen::releaseIsCompleted($step);
            }

            /*
             * Lo step viene **riletto** dentro la transazione, con i campi in eager
             * loading: quello che il chiamante ha in mano e lo stato di un istante
             * prima, e fra la lettura della schermata e l'invio un altro
             * responsabile puo avere chiuso, oppure la release puo essere cambiata.
             *
             * Un solo caricamento per i campi, non uno per campo: il costo della
             * lettura non dipende dalla lunghezza del form.
             */
            $step = ReleaseStep::query()
                ->whereKey($step->getKey())
                ->with('fields')
                ->firstOrFail();

            if ($step->status === ReleaseStepStatus::Completed) {
                throw StepIsNotOpen::stepIsCompleted($step);
            }

            if ($step->status !== ReleaseStepStatus::Active) {
                throw StepIsNotOpen::stepIsBlocked($step);
            }

            $normalized = $this->normalize($step, $values);

            $validator = Validator::make($normalized, $step->closingRules(), [], $step->closingAttributes());

            if ($validator->fails()) {
                // Il `MessageBag` viaggia con l'eccezione: la schermata rende gli
                // stessi messaggi sui campi che li hanno prodotti, senza
                // rivalidare — due validazioni sullo stesso valore sono due regole
                // destinate a divergere.
                throw StepValuesAreInvalid::with($validator->errors());
            }

            $now = now();

            /*
             * Una scrittura per campo, e non un `upsert` di massa: l'unica forma
             * bulk portabile richiederebbe un `ON CONFLICT` mirato alla chiave
             * primaria su una tabella che ha **anche** l'unicita
             * `(release_step_id, position)`, e SQLite non garantisce quale dei due
             * vincoli valuta per primo — il caso in cui sbaglia e un errore di
             * scrittura, non un dato sbagliato, ma resta un fallimento in
             * transazione. Il numero di campi di uno step e fissato dal template ed
             * e dell'ordine della decina: non e la catena che cresce senza limiti
             * contro cui il PRD mette in guardia.
             */
            foreach ($step->fields as $field) {
                $field->value = $normalized[$field->id];
                $field->save();
            }

            /*
             * Chiusura in **compare-and-swap**: un solo update condizionato allo
             * stato `attivo`. Zero righe aggiornate significa che un'altra
             * transazione e passata prima — il doppio invio — e allora la
             * transazione viene annullata per intero: nessun valore scritto, nessun
             * evento, un solo avanzamento.
             *
             * La condizione vive nella clausola `where` e non in un `if` sopra:
             * fra il controllo e la scrittura resterebbe la finestra che si sta
             * chiudendo.
             */
            $closed = ReleaseStep::query()
                ->whereKey($step->getKey())
                ->where('status', ReleaseStepStatus::Active->value)
                ->update([
                    'status' => ReleaseStepStatus::Completed->value,
                    'completed_by' => $actor->id,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($closed === 0) {
                /*
                 * Il rifiuto dice quale delle due cose e successa. Sull'ultimo step
                 * l'altro invio non ha passato il flusso a nessuno: ha concluso il
                 * rilascio, e dire "il flusso e gia passato al responsabile
                 * successivo" sarebbe falso quanto la promessa che US-006 ha tolto
                 * dai testi.
                 */
                throw $step->nextStep() === null
                    ? StepAlreadyClosed::whileConcludingRelease($step)
                    : StepAlreadyClosed::during($step);
            }

            /*
             * L'evento di chiusura viene scritto **prima** del bivio perche
             * appartiene a entrambi i rami: uno step si chiude sia quando la catena
             * prosegue sia quando finisce. Scriverlo due volte piu sotto darebbe due
             * copie della stessa riga, destinate a divergere alla prima modifica del
             * payload.
             *
             * Il payload **non** contiene i valori forniti: vivono sui campi dello
             * snapshot, e duplicarli darebbe due fonti per lo stesso dato: il
             * dettaglio della release li mostra da la, e due copie divergerebbero
             * alla prima correzione.
             */
            ReleaseEvent::create([
                'release_id' => $release->id,
                'release_step_id' => $step->id,
                'user_id' => $actor->id,
                'action' => ReleaseEventAction::StepCompleted,
                'payload' => [
                    'position' => $step->position,
                    'step' => $step->name,
                    'fields_filled' => collect($normalized)->reject(fn (?string $value): bool => $value === null)->count(),
                ],
            ]);

            $next = $step->nextStep();

            if ($next === null) {
                /*
                 * Ramo terminale della catena: lo step chiuso era l'ultimo, quindi la
                 * release e consegnata (FR-017). La conclusione avviene **qui dentro**,
                 * nella stessa transazione: una release `in_corso` senza alcuno step
                 * attivo sarebbe la violazione dell'invariante — al massimo uno step
                 * attivo per release, **zero** solo quando la release e conclusa.
                 */
                $this->completeRelease($release, $step, $actor, $now);

                return $this->realign($step, $release, $actor, $now);
            }

            $activated = ReleaseStep::query()
                ->whereKey($next->getKey())
                ->where('status', ReleaseStepStatus::Blocked->value)
                ->update([
                    'status' => ReleaseStepStatus::Active->value,
                    'updated_at' => $now,
                ]);

            if ($activated === 0) {
                // Lo step successivo non era piu bloccato: un altro invio ha gia
                // fatto avanzare la catena. Stesso rifiuto, stessa transazione
                // annullata.
                throw StepAlreadyClosed::during($step);
            }

            $next->loadMissing('assignedUser:id,name');

            /*
             * Due eventi, non uno: il registro documenta le **transizioni** (FR-016),
             * e qui ne avvengono due — un passaggio si chiude e un altro si apre.
             * Riunirle direbbe meno di quello che e successo.
             */
            ReleaseEvent::create([
                'release_id' => $release->id,
                'release_step_id' => $next->id,
                'user_id' => $actor->id,
                'action' => ReleaseEventAction::StepActivated,
                'payload' => [
                    'position' => $next->position,
                    'step' => $next->name,
                    'responsible' => $next->assignedUser->name,
                ],
            ]);

            return $this->realign($step, $release, $actor, $now);
        });
    }

    /**
     * Riporta sul modello in memoria cio che la transazione ha scritto.
     *
     * La chiusura passa dal query builder, quindi senza questo il chiamante
     * riceverebbe uno step che dice ancora "attivo". `forceFill` perche
     * `completed_by` e `completed_at` non sono attributi assegnabili in massa — li
     * scrive solo questa Action.
     *
     * La release viaggia con lo step su **entrambi** i rami: un contratto di ritorno
     * che a volte porta la relazione e a volte no farebbe pagare al chiamante una
     * query pigra a seconda di dove la catena e arrivata.
     */
    private function realign(ReleaseStep $step, Release $release, User $actor, CarbonInterface $now): ReleaseStep
    {
        return $step->setRelation('release', $release)->forceFill([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $actor->id,
            'completed_at' => $now,
        ])->syncOriginal();
    }

    /**
     * Conclude la release: stato `conclusa`, autore e istante della consegna, evento
     * nel registro.
     *
     * Resta un metodo **privato** e non diventa una Action a se stante, ed e una
     * deviazione consapevole dalla regola "una Action per operazione di dominio":
     * una `CompleteRelease` invocabile dall'esterno sarebbe un secondo percorso di
     * scrittura sullo stato della release, cioe l'opposto dell'invariante che questa
     * classe esiste per tenere — chiusura dello step, avanzamento o conclusione, ed
     * eventi in **una sola transazione**. Una release si conclude chiudendo il suo
     * ultimo step, e non c'e un altro modo per cui abbia senso.
     *
     * @throws StepAlreadyClosed se un altro invio ha gia concluso la release
     */
    private function completeRelease(Release $release, ReleaseStep $step, User $actor, CarbonInterface $now): void
    {
        /*
         * Conclusione in **compare-and-swap**, per lo stesso motivo gia registrato in
         * `.ai/rules/releases.md` per la chiusura dello step: su SQLite il
         * `lockForUpdate()` preso in cima non produce SQL — la grammatica non
         * supporta `FOR UPDATE` — quindi la garanzia effettiva contro la doppia
         * conclusione e questo update condizionato a `in_corso`. Su MySQL e
         * PostgreSQL e il lock a serializzare, e il vincolo di portabilita
         * (`.ai/rules/general.md`) impone che il codice resti corretto su tutti e tre
         * i motori: nessuno dei due meccanismi e ridondante.
         */
        $completed = Release::query()
            ->whereKey($release->getKey())
            ->where('status', ReleaseStatus::InProgress->value)
            ->update([
                'status' => ReleaseStatus::Completed->value,
                'completed_by' => $actor->id,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($completed === 0) {
            throw StepAlreadyClosed::whileConcludingRelease($step);
        }

        /*
         * Il payload non conta gli step della catena: la posizione dell'ultimo dice
         * gia quanti sono, e contarli sarebbe una query per un dato gia in mano.
         */
        ReleaseEvent::create([
            'release_id' => $release->id,
            'release_step_id' => $step->id,
            'user_id' => $actor->id,
            'action' => ReleaseEventAction::ReleaseCompleted,
            'payload' => [
                'label' => $release->label,
                'step' => $step->name,
                'position' => $step->position,
            ],
        ]);

        /*
         * `forceFill` perche `completed_by` e `completed_at` non sono attributi
         * assegnabili in massa: li scrive solo questa Action.
         */
        $release->forceFill([
            'status' => ReleaseStatus::Completed,
            'completed_by' => $actor->id,
            'completed_at' => $now,
        ])->syncOriginal();
    }

    /**
     * Valori normalizzati, indicizzati per identificativo di campo.
     *
     * Si itera sui campi dello **snapshot** e non sulle chiavi ricevute: un
     * identificativo che non appartiene a questo step viene ignorato invece di
     * essere scritto, e un campo non inviato diventa `null` — cioe viene rifiutato
     * da `required` invece di restare al valore di prima.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    private function normalize(ReleaseStep $step, array $values): array
    {
        return $step->fields
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => $field->normalizeValue($values[$field->id] ?? null),
            ])
            ->all();
    }
}
