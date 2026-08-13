<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\CloseStep;
use App\Enums\FieldType;
use App\Enums\ReleaseEventAction;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\StepAlreadyClosed;
use App\Exceptions\StepIsNotOpen;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * I criteri che nessuna prova manuale puo dimostrare: due richieste di chiusura
 * dello stesso step producono **un solo** avanzamento — e due dell'ultimo step una
 * sola conclusione — mentre una transazione interrotta a meta non lascia ne lo step
 * chiuso ne la release conclusa.
 *
 * **Perche non si simulano due processi.** La suite gira su SQLite, dove due
 * connessioni concorrenti non sono riproducibili in modo deterministico (in memoria
 * sarebbero due database distinti; su file il secondo processo attenderebbe il
 * `busy_timeout` e il test diventerebbe una gara con il cronometro). Quello che
 * viene effettivamente verificato e la difesa che regge su **tutti** i motori: il
 * compare-and-swap sullo stato. Il `lockForUpdate()` sulla riga della release non
 * produce SQL su SQLite — la grammatica non supporta `FOR UPDATE` — quindi qui non
 * e osservabile, ed e per questo che il codice non si affida ad esso da solo (vedi
 * il commento in `App\Actions\Releases\CloseStep`).
 */
class CloseStepConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_submission_of_the_same_step_does_not_advance_the_chain_twice(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $actor = $step->assignedUser;
        $values = $this->validValuesFor($step);

        app(CloseStep::class)->handle($step, $values, $actor);

        /*
         * La seconda chiamata parte dal modello che il chiamante aveva in mano
         * **prima** della prima: e il doppio invio dell'interfaccia, dove la
         * seconda richiesta porta con se uno stato di un istante prima.
         *
         * Il rifiuto arriva dalla rilettura dentro la transazione, cioe prima del
         * compare-and-swap: `StepIsNotOpen` e non `StepAlreadyClosed`. La
         * differenza e voluta — il primo dice "questo step e chiuso", il secondo
         * "e stato chiuso mentre stavi chiudendo" — e per l'utente il risultato e
         * lo stesso: un solo avanzamento.
         */
        try {
            app(CloseStep::class)->handle($step, $values, $actor);
            $this->fail('Il secondo invio ha prodotto un secondo avanzamento.');
        } catch (StepIsNotOpen $refused) {
            $this->assertSame('releases.closing_blocked_step_completed', $refused->reasonKey);
        }

        $this->assertSame(1, $release->steps()->where('status', ReleaseStepStatus::Completed->value)->count());
        $this->assertSame(1, $release->steps()->where('status', ReleaseStepStatus::Active->value)->count());

        // Due eventi in totale, non quattro.
        $this->assertSame(2, ReleaseEvent::query()->where('release_id', $release->id)->count());
    }

    public function test_a_closing_that_loses_the_race_is_refused_and_leaves_nothing_behind(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $actor = $step->assignedUser;
        $rival = User::factory()->create();

        /*
         * L'interferenza viene iniettata nel punto esatto che il compare-and-swap
         * difende: dopo che questa transazione ha letto lo stato `attivo` e
         * validato i valori, e prima che scriva la chiusura. La scrittura del primo
         * campo e il gancio piu vicino a quel punto.
         *
         * L'altro invio passa dalla stessa connessione, quindi vive dentro questa
         * transazione e l'annullamento lo porta via insieme al resto: cio che il
         * test verifica non e che l'avanzamento rivale sopravviva, ma che **questa**
         * chiusura venga rifiutata invece di scrivere la seconda.
         */
        $interfered = false;

        ReleaseStepField::saved(function () use (&$interfered, $step, $rival): void {
            if ($interfered) {
                return;
            }

            $interfered = true;

            ReleaseStep::query()
                ->whereKey($step->getKey())
                ->where('status', ReleaseStepStatus::Active->value)
                ->update([
                    'status' => ReleaseStepStatus::Completed->value,
                    'completed_by' => $rival->id,
                    'completed_at' => now(),
                ]);
        });

        try {
            app(CloseStep::class)->handle($step, $this->validValuesFor($step), $actor);
            $this->fail('La chiusura ha sovrascritto un avanzamento gia avvenuto.');
        } catch (StepAlreadyClosed) {
            $this->assertTrue($interfered, 'L\'interferenza non e mai avvenuta: il test non sta misurando niente.');
        }

        $this->assertSame(ReleaseStepStatus::Active, $step->fresh()->status);
        $this->assertSame(ReleaseStepStatus::Blocked, $release->steps->get(1)->fresh()->status);
        $this->assertSame(0, ReleaseEvent::count());
        $this->assertSame(
            0,
            ReleaseStepField::query()->whereNotNull('value')->count(),
            'Una chiusura rifiutata ha lasciato dei valori scritti.'
        );
    }

    public function test_a_failure_halfway_through_leaves_the_step_open(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        // Guardiano temporaneo sul registro: interrompe la transazione **dopo** la
        // chiusura dello step e l'attivazione del successivo, e prima della
        // scrittura dell'evento. Se le tre scritture non fossero una sola
        // transazione, la release resterebbe con uno step chiuso e nessuna traccia.
        ReleaseEvent::creating(function (): never {
            throw new LogicException('Guardiano del test: interruzione dopo la chiusura.');
        });

        try {
            app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);
            $this->fail('La transazione interrotta non ha propagato il fallimento.');
        } catch (LogicException) {
            // Atteso: il guardiano ha interrotto la scrittura del registro.
        }

        $step = $step->fresh();

        $this->assertSame(ReleaseStepStatus::Active, $step->status);
        $this->assertNull($step->completed_by);
        $this->assertNull($step->completed_at);
        $this->assertSame(ReleaseStepStatus::Blocked, $release->steps->get(1)->fresh()->status);
        $this->assertSame(0, ReleaseEvent::count());
        $this->assertSame(
            0,
            ReleaseStepField::query()->whereNotNull('value')->count(),
            'I valori sono stati scritti fuori dalla transazione dell\'avanzamento.'
        );
    }

    public function test_a_second_submission_of_the_last_step_does_not_complete_the_release_twice(): void
    {
        $release = $this->releaseInProgress(steps: 1);
        $step = $release->steps->first();
        $actor = $step->assignedUser;
        $values = $this->validValuesFor($step);

        app(CloseStep::class)->handle($step, $values, $actor);

        $concluded = $release->fresh();

        /*
         * Come sopra, la seconda chiamata parte dal modello di un istante prima. Il
         * rifiuto arriva dal controllo sullo stato della release, che precede il
         * compare-and-swap: `StepIsNotOpen` e non `StepAlreadyClosed`. Il
         * compare-and-swap sulla conclusione resta comunque la difesa che regge se
         * quel controllo cambiasse forma — e la sola che vale su tutti i motori,
         * come per la chiusura dello step.
         */
        try {
            app(CloseStep::class)->handle($step, $values, $actor);
            $this->fail('Il secondo invio ha prodotto una seconda conclusione.');
        } catch (StepIsNotOpen $refused) {
            $this->assertSame('releases.closing_blocked_release_completed', $refused->reasonKey);
        }

        $this->assertSame(
            1,
            ReleaseEvent::query()
                ->where('release_id', $release->id)
                ->where('action', ReleaseEventAction::ReleaseCompleted->value)
                ->count(),
            'La release e stata conclusa due volte nel registro.'
        );

        // Autore e istante restano quelli della prima conclusione: la seconda non
        // ha riscritto la consegna a nome di chi e arrivato dopo.
        $release = $release->fresh();

        $this->assertSame($concluded->completed_by, $release->completed_by);
        $this->assertEquals($concluded->completed_at, $release->completed_at);
    }

    public function test_a_lost_race_on_the_last_step_says_the_release_was_concluded_not_handed_over(): void
    {
        $release = $this->releaseInProgress(steps: 1);
        $step = $release->steps->first();
        $rival = User::factory()->create();

        // Stessa interferenza del test sopra, sull'ultimo step della catena: qui
        // l'altro invio non ha passato il flusso a nessuno, ha concluso il rilascio,
        // e il messaggio deve dire quello.
        $interfered = false;

        ReleaseStepField::saved(function () use (&$interfered, $step, $rival): void {
            if ($interfered) {
                return;
            }

            $interfered = true;

            ReleaseStep::query()
                ->whereKey($step->getKey())
                ->where('status', ReleaseStepStatus::Active->value)
                ->update([
                    'status' => ReleaseStepStatus::Completed->value,
                    'completed_by' => $rival->id,
                    'completed_at' => now(),
                ]);
        });

        try {
            app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);
            $this->fail('La chiusura ha sovrascritto una conclusione gia avvenuta.');
        } catch (StepAlreadyClosed $refused) {
            $this->assertTrue($interfered, 'L\'interferenza non e mai avvenuta: il test non sta misurando niente.');
            $this->assertSame('releases.closing_already_concluded', $refused->reasonKey);
        }

        $this->assertSame(ReleaseStatus::InProgress, $release->fresh()->status);
        $this->assertSame(0, ReleaseEvent::count());
    }

    public function test_an_interrupted_completion_leaves_neither_the_step_closed_nor_the_release_completed(): void
    {
        $release = $this->releaseInProgress(steps: 1);
        $step = $release->steps->first();

        /*
         * Guardiano mirato sul solo evento di conclusione: interrompe la transazione
         * **dopo** la chiusura dello step e dopo il passaggio della release a
         * conclusa. Se quelle scritture non fossero una sola transazione, resterebbe
         * una release conclusa con l'ultimo step ancora aperto — o il contrario.
         */
        ReleaseEvent::creating(function (ReleaseEvent $event): void {
            if ($event->action === ReleaseEventAction::ReleaseCompleted) {
                throw new LogicException('Guardiano del test: interruzione dopo la conclusione.');
            }
        });

        try {
            app(CloseStep::class)->handle($step, $this->validValuesFor($step), $step->assignedUser);
            $this->fail('La transazione interrotta non ha propagato il fallimento.');
        } catch (LogicException) {
            // Atteso: il guardiano ha interrotto la scrittura del registro.
        }

        $release = $release->fresh();
        $step = $step->fresh();

        $this->assertSame(ReleaseStatus::InProgress, $release->status);
        $this->assertNull($release->completed_by);
        $this->assertNull($release->completed_at);

        $this->assertSame(ReleaseStepStatus::Active, $step->status);
        $this->assertNull($step->completed_at);

        // Nemmeno l'evento di chiusura dello step, scritto prima: l'annullamento
        // riguarda l'intera transazione.
        $this->assertSame(0, ReleaseEvent::count());
        $this->assertSame(
            0,
            ReleaseStepField::query()->whereNotNull('value')->count(),
            'Una conclusione interrotta ha lasciato dei valori scritti.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validValuesFor(ReleaseStep $step): array
    {
        return $step->fields()->get()
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => match ($field->type) {
                    FieldType::ShortText => '2.4.0',
                    FieldType::LongText => 'Verifica completata senza anomalie bloccanti.',
                    FieldType::Link => 'https://ci.gruppoexcellence.com/pipeline/4471',
                    FieldType::Confirmation => true,
                },
            ])
            ->all();
    }

    /**
     * Release in corso con una catena di `steps` step: il primo attivo con due
     * campi, gli altri bloccati.
     */
    private function releaseInProgress(int $steps = 2): Release
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
        }

        return $release->load('steps.fields', 'steps.assignedUser');
    }
}
