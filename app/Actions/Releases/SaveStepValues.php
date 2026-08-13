<?php

namespace App\Actions\Releases;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\StepIsNotOpen;
use App\Exceptions\StepValuesAreInvalid;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Scrive i valori compilati **senza** far avanzare la catena.
 *
 * Il mockup della chiusura espone "Salva senza chiudere" accanto alla chiusura, e
 * non e una comodita: su uno step con due testi lunghi, compilato da telefono fra
 * una riunione e l'altra, e la differenza fra uno strumento usabile e uno da cui si
 * esce perdendo il lavoro. La chiusura resta un atto separato e definitivo.
 *
 * Nessuna transizione di stato, nessuna scrittura su `completed_by` o
 * `completed_at`, e **nessuna riga nel registro**: il registro documenta le
 * transizioni (FR-016), e una bozza non lo e. `App\Enums\ReleaseEventAction` e nato
 * completo in US-004 e non prevede questo caso — aggiungerne uno gonfierebbe lo
 * storico di righe che non raccontano nulla sul processo, proprio nel registro che
 * deve valere come prova di cosa e successo.
 */
class SaveStepValues
{
    /**
     * @param  array<string, mixed>  $values  valori compilati, indicizzati per identificativo di campo
     * @param  User  $actor  chi salva: nella firma per simmetria con `CloseStep` e con la
     *                       Policy che ha deciso, non scritto da nessuna parte — una bozza
     *                       non lascia traccia nel registro, e attribuirla in colonna
     *                       direbbe che qualcuno ha compilato quando nessuno ha dichiarato
     *                       nulla
     *
     * @throws StepIsNotOpen se la release e conclusa, o lo step e bloccato o gia completato
     * @throws StepValuesAreInvalid se un valore fornito e malformato
     */
    public function handle(ReleaseStep $step, array $values, User $actor): ReleaseStep
    {
        return DB::transaction(function () use ($step, $values): ReleaseStep {
            /*
             * Stesse precondizioni di stato della chiusura: una bozza su uno step
             * bloccato o completato non ha significato — il primo non e ancora suo
             * turno, il secondo e in sola lettura.
             *
             * Nessun lock e nessun compare-and-swap, a differenza di `CloseStep`:
             * qui non si legge uno stato per riscriverlo, e due salvataggi
             * concorrenti dello stesso step lasciano l'ultimo valore scritto — che e
             * il comportamento atteso di una bozza, non una corsa da chiudere.
             */
            $release = Release::query()->whereKey($step->release_id)->firstOrFail();

            if ($release->status !== ReleaseStatus::InProgress) {
                throw StepIsNotOpen::releaseIsCompleted($step);
            }

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

            $normalized = $step->fields
                ->mapWithKeys(fn (ReleaseStepField $field): array => [
                    $field->id => $field->normalizeValue($values[$field->id] ?? null),
                ])
                ->all();

            $validator = Validator::make(
                $normalized,
                $this->draftRules($step),
                [],
                $step->closingAttributes()
            );

            if ($validator->fails()) {
                throw StepValuesAreInvalid::with($validator->errors());
            }

            foreach ($step->fields as $field) {
                $field->value = $normalized[$field->id];
                $field->save();
            }

            return $step;
        });
    }

    /**
     * Regole di **forma** sui soli valori compilati: le stesse della chiusura, con
     * l'obbligatorieta rilassata.
     *
     * Una bozza incompleta e il suo scopo, quindi `required` diventa `nullable` e
     * `accepted` sparisce. Ma salvare un link malformato significherebbe
     * riproporlo identico e rotto alla ripresa, quindi il resto resta: e per questo
     * le regole sono **derivate** da `ReleaseStepField::closingRules()` invece di
     * essere riscritte — una seconda copia divergerebbe dalla prima al primo tipo
     * di campo aggiunto.
     *
     * @return array<string, list<mixed>>
     */
    private function draftRules(ReleaseStep $step): array
    {
        return array_map(
            fn (array $rules): array => array_values(array_map(
                fn (mixed $rule): mixed => $rule === 'required' ? 'nullable' : $rule,
                array_filter($rules, fn (mixed $rule): bool => $rule !== 'accepted'),
            )),
            $step->closingRules()
        );
    }
}
