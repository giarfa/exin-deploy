<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\SaveStepValues;
use App\Enums\FieldType;
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
 * Il salvataggio senza chiusura e una bozza: accetta un form incompleto e non fa
 * avanzare nulla. Le due cose vanno verificate insieme, perche una bozza che
 * facesse avanzare la catena sarebbe una chiusura mascherata, e una chiusura che
 * accettasse un form incompleto renderebbe inutile l'obbligatorieta dei campi.
 */
class SaveStepValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_values_are_saved_without_closing_the_step(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $short = $step->fields->firstWhere('type', FieldType::ShortText);

        app(SaveStepValues::class)->handle($step, [$short->id => ' 2.4.0 '], $step->assignedUser);

        $this->assertSame('2.4.0', $short->fresh()->value);

        $step = $step->fresh();

        $this->assertSame(ReleaseStepStatus::Active, $step->status);
        $this->assertNull($step->completed_by);
        $this->assertNull($step->completed_at);
        // Il successivo non e stato toccato: nessun avanzamento mascherato.
        $this->assertSame(ReleaseStepStatus::Blocked, $release->steps->get(1)->fresh()->status);
    }

    public function test_a_draft_leaves_no_row_in_the_register(): void
    {
        // Il registro documenta le transizioni (FR-016): una bozza non lo e, e
        // riempirlo di salvataggi renderebbe illeggibile cio che conta.
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        app(SaveStepValues::class)->handle($step, $this->partialValuesFor($step), $step->assignedUser);

        $this->assertSame(0, ReleaseEvent::query()->where('release_id', $release->id)->count());
    }

    public function test_a_missing_required_value_does_not_prevent_the_draft(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $long = $step->fields->firstWhere('type', FieldType::LongText);

        // Nessun campo obbligatorio compilato: e esattamente lo scopo della bozza.
        app(SaveStepValues::class)->handle($step, [$long->id => 'Ripreso domani.'], $step->assignedUser);

        $this->assertSame('Ripreso domani.', $long->fresh()->value);
        $this->assertSame(ReleaseStepStatus::Active, $step->fresh()->status);
    }

    public function test_a_malformed_link_is_refused_even_in_a_draft(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $link = $step->fields->firstWhere('type', FieldType::Link);

        try {
            app(SaveStepValues::class)->handle(
                $step,
                [$link->id => 'ci.gruppoexcellence/report 4471'],
                $step->assignedUser
            );
            $this->fail('Un indirizzo malformato e stato salvato in bozza.');
        } catch (StepValuesAreInvalid $refused) {
            $this->assertTrue($refused->errors->has($link->id));
        }

        // Salvarlo significherebbe riproporlo identico e rotto alla ripresa.
        $this->assertNull($link->fresh()->value);
    }

    public function test_an_unticked_required_confirmation_does_not_prevent_the_draft(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $confirmation = $step->fields->firstWhere('type', FieldType::Confirmation);
        $short = $step->fields->firstWhere('type', FieldType::ShortText);

        app(SaveStepValues::class)->handle(
            $step,
            [$short->id => '2.4.0', $confirmation->id => false],
            $step->assignedUser
        );

        $this->assertSame('2.4.0', $short->fresh()->value);
        $this->assertNull($confirmation->fresh()->value);
    }

    public function test_a_field_not_sent_is_emptied_and_not_left_at_the_previous_value(): void
    {
        /*
         * Il form invia sempre tutti i campi dello step: un campo assente dalla
         * richiesta e un campo svuotato da chi compila, non un campo da lasciare
         * com'era. Il contrario renderebbe impossibile cancellare un valore.
         */
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $short = $step->fields->firstWhere('type', FieldType::ShortText);
        $long = $step->fields->firstWhere('type', FieldType::LongText);

        app(SaveStepValues::class)->handle($step, [$short->id => '2.4.0'], $step->assignedUser);
        $this->assertSame('2.4.0', $short->fresh()->value);

        app(SaveStepValues::class)->handle($step, [$long->id => 'Solo note.'], $step->assignedUser);

        $this->assertNull($short->fresh()->value);
        $this->assertSame('Solo note.', $long->fresh()->value);
    }

    public function test_a_blocked_step_cannot_be_filled(): void
    {
        $release = $this->releaseInProgress();
        $blocked = $release->steps->get(1);

        try {
            app(SaveStepValues::class)->handle($blocked, $this->partialValuesFor($blocked), $blocked->assignedUser);
            $this->fail('Uno step bloccato ha accettato una bozza.');
        } catch (StepIsNotOpen $refused) {
            $this->assertSame('releases.closing_blocked_step_blocked', $refused->reasonKey);
        }

        $this->assertSame(
            0,
            $blocked->fields()->whereNotNull('value')->count(),
            'Una bozza e stata scritta su uno step bloccato.'
        );
    }

    public function test_a_completed_step_cannot_be_filled(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $step->update(['status' => ReleaseStepStatus::Completed]);

        try {
            app(SaveStepValues::class)->handle($step->fresh(), $this->partialValuesFor($step), $step->assignedUser);
            $this->fail('Uno step completato ha accettato una bozza.');
        } catch (StepIsNotOpen $refused) {
            $this->assertSame('releases.closing_blocked_step_completed', $refused->reasonKey);
        }
    }

    /**
     * Valori parziali plausibili: solo i due campi di testo.
     *
     * @return array<string, mixed>
     */
    private function partialValuesFor(ReleaseStep $step): array
    {
        return [
            $step->fields->firstWhere('type', FieldType::ShortText)->id => '2.4.0',
            $step->fields->firstWhere('type', FieldType::LongText)->id => 'Bozza in corso.',
        ];
    }

    /**
     * Release in corso con due step: il primo attivo con un campo per tipo, il
     * secondo bloccato.
     */
    private function releaseInProgress(): Release
    {
        $release = Release::factory()->create();
        $responsible = User::factory()->create();

        foreach ([1, 2] as $position) {
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
