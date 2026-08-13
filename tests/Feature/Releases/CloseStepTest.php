<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\CloseStep;
use App\Enums\FieldType;
use App\Enums\ReleaseEventAction;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\StepIsNotOpen;
use App\Exceptions\StepValuesAreInvalid;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il percorso che la spec deve dimostrare: lo step attivo si chiude con i valori
 * richiesti, il successivo si attiva, e ogni rifiuto lascia la catena esattamente
 * come era.
 *
 * Le prove sui rifiuti verificano sempre **tre** assenze — nessun valore scritto,
 * nessuna transizione, nessun evento — perche un rifiuto che avesse scritto una sola
 * di quelle tre lascerebbe una release che nessuna schermata sa raccontare.
 */
class CloseStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_responsible_closes_the_active_step_and_the_next_one_becomes_active(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $actor = $step->assignedUser;

        $closed = app(CloseStep::class)->handle($step, $this->validValuesFor($step), $actor);

        $this->assertSame(ReleaseStepStatus::Completed, $closed->status);

        $step = $step->fresh();

        $this->assertSame(ReleaseStepStatus::Completed, $step->status);
        $this->assertSame($actor->id, $step->completed_by);
        $this->assertNotNull($step->completed_at);

        $next = $release->steps->get(1)->fresh();

        $this->assertSame(ReleaseStepStatus::Active, $next->status);
        // Il terzo attende ancora: la catena avanza di un passo per volta.
        $this->assertSame(ReleaseStepStatus::Blocked, $release->steps->get(2)->fresh()->status);
    }

    public function test_the_provided_values_are_written_on_the_frozen_fields(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);

        $written = $step->fields()->get()->keyBy(fn (ReleaseStepField $field): string => $field->type->value);

        $this->assertSame('2.4.0', $written[FieldType::ShortText->value]->value);
        $this->assertSame('https://ci.gruppoexcellence.com/pipeline/4471', $written[FieldType::Link->value]->value);
        // La conferma spuntata vale `'1'`, non `'true'` ne `'on'`.
        $this->assertSame('1', $written[FieldType::Confirmation->value]->value);
    }

    public function test_the_closing_writes_both_transitions_in_the_register(): void
    {
        // Tempo fermo: l'istante registrato e un criterio di accettazione, e
        // confrontarlo con `now()` senza fissarlo verificherebbe soltanto che due
        // orologi diversi si somigliano.
        $this->freezeTime();

        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $next = $release->steps->get(1);
        $actor = $step->assignedUser;

        app(CloseStep::class)->handle($step, $this->validValuesFor($step), $actor);

        $events = ReleaseEvent::query()->where('release_id', $release->id)->get();

        $this->assertCount(2, $events);

        $completed = $events->firstWhere('action', ReleaseEventAction::StepCompleted);
        $activated = $events->firstWhere('action', ReleaseEventAction::StepActivated);

        $this->assertNotNull($completed);
        $this->assertSame($step->id, $completed->release_step_id);
        $this->assertSame($actor->id, $completed->user_id);
        $this->assertSame(now()->toDateTimeString(), $completed->created_at->toDateTimeString());
        $this->assertSame($step->name, $completed->payload['step']);

        $this->assertNotNull($activated);
        $this->assertSame($next->id, $activated->release_step_id);
        $this->assertSame($next->assignedUser->name, $activated->payload['responsible']);

        // I valori forniti vivono sui campi: duplicarli nel registro darebbe due
        // fonti per lo stesso dato.
        $this->assertArrayNotHasKey('values', $completed->payload);
    }

    public function test_a_missing_required_value_is_refused_and_names_the_field(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $required = $step->fields->firstWhere('type', FieldType::ShortText);

        $values = $this->validValuesFor($step);
        $values[$required->id] = '   ';

        try {
            app(CloseStep::class)->handle($step, $values, $step->assignedUser);
            $this->fail('La chiusura con un campo obbligatorio vuoto non e stata rifiutata.');
        } catch (StepValuesAreInvalid $refused) {
            $this->assertStringContainsString($required->label, $refused->errors->first($required->id));
        }

        $this->assertNothingChanged($release, $step);
    }

    public function test_a_malformed_link_is_refused(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $link = $step->fields->firstWhere('type', FieldType::Link);

        $values = $this->validValuesFor($step);
        $values[$link->id] = 'ci.gruppoexcellence/report 4471';

        try {
            app(CloseStep::class)->handle($step, $values, $step->assignedUser);
            $this->fail('La chiusura con un indirizzo malformato non e stata rifiutata.');
        } catch (StepValuesAreInvalid $refused) {
            $message = $refused->errors->first($link->id);

            // Il rifiuto dice cosa correggere, non soltanto che qualcosa non va.
            $this->assertStringContainsString(__('validation.well_formed_link.missing_scheme'), $message);
            $this->assertStringContainsString(__('validation.well_formed_link.contains_whitespace'), $message);
        }

        $this->assertNothingChanged($release, $step);
    }

    public function test_a_required_confirmation_left_unticked_is_refused(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $confirmation = $step->fields->firstWhere('type', FieldType::Confirmation);

        $values = $this->validValuesFor($step);
        $values[$confirmation->id] = false;

        try {
            app(CloseStep::class)->handle($step, $values, $step->assignedUser);
            $this->fail('La chiusura con una conferma obbligatoria non spuntata non e stata rifiutata.');
        } catch (StepValuesAreInvalid $refused) {
            $this->assertTrue($refused->errors->has($confirmation->id));
        }

        $this->assertNothingChanged($release, $step);
    }

    public function test_an_optional_field_left_empty_does_not_prevent_the_closing(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $optional = $step->fields->firstWhere('is_required', false);

        $values = $this->validValuesFor($step);
        $values[$optional->id] = '';

        app(CloseStep::class)->handle($step, $values, $step->assignedUser);

        $this->assertSame(ReleaseStepStatus::Completed, $step->fresh()->status);
        // `null` e non `''`: il dettaglio della release deve poter dire "non fornito".
        $this->assertNull($optional->fresh()->value);
    }

    public function test_only_one_step_stays_active_along_the_whole_chain(): void
    {
        // E l'invariante che il PRD dichiara portante, e non si dimostra guardando
        // il codice: va percorsa la catena.
        $release = $this->releaseInProgress();

        foreach ([0, 1] as $index) {
            $step = $release->steps->get($index)->fresh();

            app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);

            $this->assertSame(
                1,
                $release->steps()->where('status', ReleaseStepStatus::Active->value)->count(),
                'La release non ha esattamente uno step attivo dopo la chiusura del numero '.($index + 1).'.'
            );
        }

        $this->assertSame(ReleaseStepStatus::Active, $release->steps->get(2)->fresh()->status);
    }

    public function test_closing_the_last_step_completes_the_release(): void
    {
        $this->freezeTime();

        $release = $this->releaseInProgress();
        $last = $release->steps->last();
        $actor = $last->assignedUser;

        $closed = $this->closeWholeChain($release);

        // Lo stesso confine che US-005 verificava al contrario: la chiusura
        // dell'ultimo step non lascia piu la catena ferma, la consegna.
        $this->assertSame(ReleaseStepStatus::Completed, $closed->status);

        $release = $release->fresh();

        $this->assertSame(ReleaseStatus::Completed, $release->status);
        $this->assertSame($actor->id, $release->completed_by);
        $this->assertSame(now()->toDateTimeString(), $release->completed_at->toDateTimeString());

        // La release restituita dall'Action e riallineata quanto lo step: chi la
        // legge subito dopo la chiusura non vede un rilascio ancora "in corso".
        $this->assertSame(ReleaseStatus::Completed, $closed->release->status);
        $this->assertSame($actor->id, $closed->release->completed_by);

        $this->assertSame(ReleaseStepStatus::Completed, $last->fresh()->status);
    }

    public function test_a_single_step_chain_completes_on_the_first_closure(): void
    {
        // Una catena di un solo step non ha un successore da attivare: il primo
        // invio e gia la consegna.
        $release = $this->releaseInProgress(steps: 1);
        $step = $release->steps->first();

        app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);

        $this->assertSame(ReleaseStatus::Completed, $release->fresh()->status);
        $this->assertSame(ReleaseStepStatus::Completed, $step->fresh()->status);
    }

    public function test_a_completed_release_has_no_active_step(): void
    {
        // E il caso "zero" dell'invariante portante del PRD — al massimo uno step
        // attivo per release, nessuno quando e conclusa — e come l'altro non si
        // dimostra guardando il codice: va percorsa la catena fino in fondo.
        $release = $this->releaseInProgress();

        $this->closeWholeChain($release);

        $this->assertSame(ReleaseStatus::Completed, $release->fresh()->status);

        $this->assertSame(
            0,
            $release->steps()->where('status', ReleaseStepStatus::Active->value)->count(),
            'Una release conclusa ha ancora uno step attivo.'
        );

        $this->assertSame(
            3,
            $release->steps()->where('status', ReleaseStepStatus::Completed->value)->count(),
            'La conclusione non ha lasciato tutti gli step completati.'
        );
    }

    public function test_the_completion_is_recorded_in_the_register(): void
    {
        $this->freezeTime();

        $release = $this->releaseInProgress(steps: 1);
        $step = $release->steps->first();
        $actor = $step->assignedUser;

        app(CloseStep::class)->handle($step, $this->validValuesFor($step), $actor);

        $events = ReleaseEvent::query()->where('release_id', $release->id)->get();

        /*
         * Due righe e non tre: sul ramo terminale non c'e nessuno step da attivare,
         * e un `step_attivato` scritto qui direbbe che il flusso e passato a
         * qualcuno che non esiste.
         */
        $this->assertCount(2, $events);
        $this->assertNull($events->firstWhere('action', ReleaseEventAction::StepActivated));

        $concluded = $events->firstWhere('action', ReleaseEventAction::ReleaseCompleted);

        $this->assertNotNull($concluded);
        $this->assertSame($actor->id, $concluded->user_id);
        $this->assertSame(now()->toDateTimeString(), $concluded->created_at->toDateTimeString());
        // Lo step finale e il riferimento: e da li che la consegna e avvenuta.
        $this->assertSame($step->id, $concluded->release_step_id);
        $this->assertSame($release->label, $concluded->payload['label']);
        $this->assertSame($step->name, $concluded->payload['step']);
        $this->assertSame($step->position, $concluded->payload['position']);

        /*
         * Le due righe portano lo stesso istante — la risoluzione e il secondo, e la
         * transazione dura molto meno — quindi l'ordine verificato qui e quello di
         * **inserimento**: prima la chiusura del passaggio, poi la conclusione del
         * rilascio, che e esattamente cio che il codice garantisce.
         */
        $this->assertSame(
            [ReleaseEventAction::StepCompleted, ReleaseEventAction::ReleaseCompleted],
            $events->pluck('action')->all()
        );
    }

    public function test_a_completed_release_leaves_those_in_progress_but_stays_readable(): void
    {
        $release = $this->releaseInProgress();

        $this->closeWholeChain($release);

        $this->assertNotContains(
            $release->id,
            Release::query()->inProgress()->pluck('id')->all(),
            'Una release conclusa compare ancora fra quelle in corso.'
        );

        /*
         * Conclusa non significa archiviata: lo storico resta consultabile a tempo
         * indeterminato (AC 6), con la catena e i valori forniti al loro posto.
         */
        $readable = Release::query()->with('steps.fields')->findOrFail($release->id);

        $this->assertCount(3, $readable->steps);
        $this->assertSame('2.4.0', $readable->steps->first()->fields->firstWhere('type', FieldType::ShortText)->value);
    }

    public function test_a_blocked_step_cannot_be_closed(): void
    {
        $release = $this->releaseInProgress();
        $blocked = $release->steps->get(1);

        $this->expectException(StepIsNotOpen::class);

        try {
            app(CloseStep::class)->handle($blocked, $this->validValuesFor($blocked), $blocked->assignedUser);
        } finally {
            $this->assertNothingChanged($release, $blocked, expectedStatus: ReleaseStepStatus::Blocked);
        }
    }

    public function test_a_completed_step_is_read_only(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);

        $closed = $step->fresh();
        $closedAt = $closed->completed_at;

        try {
            app(CloseStep::class)->handle($closed, $this->validValuesFor($closed), $closed->assignedUser);
            $this->fail('Uno step gia chiuso e stato chiuso una seconda volta.');
        } catch (StepIsNotOpen $refused) {
            $this->assertSame('releases.closing_blocked_step_completed', $refused->reasonKey);
        }

        $this->assertEquals($closedAt, $step->fresh()->completed_at);
        // La seconda chiusura non ha aggiunto eventi ai due della prima.
        $this->assertSame(2, ReleaseEvent::count());
    }

    public function test_a_step_of_a_completed_release_cannot_be_closed(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $release->update(['status' => ReleaseStatus::Completed]);

        try {
            app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);
            $this->fail('Uno step di una release conclusa e stato chiuso.');
        } catch (StepIsNotOpen $refused) {
            $this->assertSame('releases.closing_blocked_release_completed', $refused->reasonKey);
        }

        $this->assertNothingChanged($release, $step);
    }

    /**
     * Dopo un rifiuto: nessun valore scritto, nessuna transizione, nessun evento.
     */
    private function assertNothingChanged(
        Release $release,
        ReleaseStep $step,
        ReleaseStepStatus $expectedStatus = ReleaseStepStatus::Active,
    ): void {
        $this->assertSame($expectedStatus, $step->fresh()->status);
        $this->assertNull($step->fresh()->completed_by);
        $this->assertNull($step->fresh()->completed_at);

        $this->assertSame(
            0,
            ReleaseStepField::query()->whereIn('release_step_id', $release->steps->modelKeys())->whereNotNull('value')->count(),
            'Un rifiuto ha comunque scritto un valore sui campi.'
        );

        $this->assertSame(0, ReleaseEvent::query()->where('release_id', $release->id)->count());

        $this->assertSame(
            1,
            $release->steps()->where('status', ReleaseStepStatus::Active->value)->count(),
            'Un rifiuto ha alterato lo step attivo della release.'
        );
    }

    /**
     * Percorre la catena chiudendo ogni step con il suo responsabile, e restituisce
     * lo step finale come l'Action lo ha restituito.
     *
     * Rilegge lo step a ogni giro: quello caricato all'inizio direbbe ancora
     * "bloccato", cioe lo stato di prima che la catena avanzasse.
     */
    private function closeWholeChain(Release $release): ReleaseStep
    {
        $closed = null;

        foreach ($release->steps as $step) {
            $step = $step->fresh();

            $closed = app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);
        }

        return $closed;
    }

    /**
     * Valori validi per ogni campo dello step, indicizzati come li invia la
     * schermata.
     *
     * @return array<string, mixed>
     */
    private function validValuesFor(ReleaseStep $step): array
    {
        return $step->fields()->get()
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => match ($field->type) {
                    FieldType::ShortText => '2.4.0',
                    FieldType::LongText => 'Nessuna anomalia bloccante rilevata durante la verifica.',
                    FieldType::Link => 'https://ci.gruppoexcellence.com/pipeline/4471',
                    FieldType::Confirmation => true,
                },
            ])
            ->all();
    }

    /**
     * Release in corso con una catena di `steps` step: il primo attivo, gli altri
     * bloccati, ciascuno con un campo per ognuno dei quattro tipi.
     */
    private function releaseInProgress(int $steps = 3): Release
    {
        $release = Release::factory()->create();
        $responsible = User::factory()->create();

        for ($position = 1; $position <= $steps; $position++) {
            $step = ReleaseStep::factory()->for($release)->create([
                'position' => $position,
                'status' => $position === 1 ? ReleaseStepStatus::Active : ReleaseStepStatus::Blocked,
                'assigned_user_id' => $responsible->id,
            ]);

            ReleaseStepField::factory()->for($step)->shortText()->create(['position' => 1, 'is_required' => true]);
            ReleaseStepField::factory()->for($step)->link()->create(['position' => 2, 'is_required' => true]);
            ReleaseStepField::factory()->for($step)->confirmation()->create(['position' => 3, 'is_required' => true]);
            ReleaseStepField::factory()->for($step)->longText()->optional()->create(['position' => 4]);
        }

        return $release->load('steps.fields', 'steps.assignedUser');
    }
}
