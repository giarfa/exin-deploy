<?php

namespace Tests\Feature\Releases;

use App\Enums\FieldType;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Il flusso di chiusura dall'interfaccia: cosa vede il responsabile, come vengono
 * resi i rifiuti, e cosa resta di uno step che non e apribile.
 *
 * Copre anche il contratto di accessibilita fissato dal mockup — riepilogo errori
 * con `role="alert"`, errori collegati al campo, obbligatorieta detta a parole —
 * perche e un criterio di accettazione e non una rifinitura: sono le tre cose che
 * rendono il form usabile senza vedere lo schermo.
 */
class CloseStepScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_responsible_sees_the_form_with_every_frozen_field(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $this->actingAs($step->assignedUser);

        $response = $this->get(route('releases.step', $step))->assertOk();

        $response->assertSee($step->name);
        $response->assertSee($release->label);
        $response->assertSee($release->project->name);
        $response->assertSee($step->instructions);

        foreach ($step->fields as $field) {
            $response->assertSee($field->label);
        }

        // Il flusso dichiara a chi passa: lo strumento non invia notifiche.
        $response->assertSee(__('releases.step_hands_over_to', [
            'name' => $release->steps->get(1)->assignedUser->name,
            'step' => $release->steps->get(1)->name,
        ]));

        $response->assertSee(__('releases.step_close_action'));
        $response->assertSee(__('releases.step_save_action'));
    }

    public function test_the_form_declares_requiredness_in_words_and_not_only_with_an_asterisk(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $this->actingAs($step->assignedUser);

        $response = $this->get(route('releases.step', $step))->assertOk();

        // Un asterisco non si annuncia: accanto c'e la parola, nascosta alla vista.
        $response->assertSee(__('releases.step_required_marker'));
        $response->assertSee('aria-hidden="true"', escape: false);
        $response->assertSee(__('releases.step_optional_hint'));

        // Ogni controllo ha un identificativo stabile, quello a cui i collegamenti
        // del riepilogo errori puntano.
        foreach ($step->fields as $field) {
            $response->assertSee('id="campo-'.$field->id.'"', escape: false);
        }
    }

    public function test_the_responsible_closes_the_step_from_the_screen(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $next = $release->steps->get(1);

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step]);

        foreach ($this->validValuesFor($step) as $field => $value) {
            $component->set('values.'.$field, $value);
        }

        $component->call('close')->assertHasNoErrors();

        $this->assertSame(ReleaseStepStatus::Completed, $step->fresh()->status);
        $this->assertSame(ReleaseStepStatus::Active, $next->fresh()->status);

        $component->assertSee(__('releases.step_closed_heading'));
        $component->assertSee(__('releases.step_closed_handed_over', [
            'name' => $next->assignedUser->name,
            'step' => $next->name,
        ]));
    }

    public function test_a_missing_required_value_is_rendered_on_the_field_and_in_the_summary(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $required = $step->fields->firstWhere('type', FieldType::ShortText);

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step])
            ->set('values.'.$required->id, '')
            ->call('close')
            ->assertHasErrors('values.'.$required->id);

        // Riepilogo in cima, annunciato e collegato al campo.
        $component->assertSee(__('releases.step_errors_heading'));
        $component->assertSee('role="alert"', escape: false);
        $component->assertSee('href="#campo-'.$required->id.'"', escape: false);

        // Errore collegato al controllo, non soltanto scritto accanto — e insieme
        // al testo di aiuto: l'aiuto dice cosa scrivere, l'errore cosa correggere.
        $component->assertSee(
            'aria-describedby="errore-'.$required->id.' aiuto-'.$required->id.'"',
            escape: false
        );
        $component->assertSee('aria-invalid="true"', escape: false);
        $component->assertSee('id="errore-'.$required->id.'"', escape: false);

        $this->assertSame(ReleaseStepStatus::Active, $step->fresh()->status);
        $this->assertSame(0, ReleaseEvent::count());
    }

    public function test_a_malformed_link_is_rendered_with_the_reason_to_correct(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $link = $step->fields->firstWhere('type', FieldType::Link);

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step]);

        foreach ($this->validValuesFor($step) as $field => $value) {
            $component->set('values.'.$field, $value);
        }

        $component->set('values.'.$link->id, 'ci.gruppoexcellence/report 4471')
            ->call('close')
            ->assertHasErrors('values.'.$link->id);

        $component->assertSee(__('validation.well_formed_link.missing_scheme'));
        $component->assertSee(__('validation.well_formed_link.contains_whitespace'));

        $this->assertSame(ReleaseStepStatus::Active, $step->fresh()->status);
    }

    public function test_an_unticked_required_confirmation_is_refused_on_the_screen(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $confirmation = $step->fields->firstWhere('type', FieldType::Confirmation);

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step]);

        foreach ($this->validValuesFor($step) as $field => $value) {
            $component->set('values.'.$field, $value);
        }

        $component->set('values.'.$confirmation->id, false)
            ->call('close')
            ->assertHasErrors('values.'.$confirmation->id);

        $this->assertSame(ReleaseStepStatus::Active, $step->fresh()->status);
    }

    public function test_the_screen_saves_a_draft_without_closing(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $short = $step->fields->firstWhere('type', FieldType::ShortText);

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step])
            ->set('values.'.$short->id, '2.4.0')
            ->call('save')
            ->assertHasNoErrors();

        $component->assertSee(__('releases.step_saved_notice'));

        $this->assertSame('2.4.0', $short->fresh()->value);
        $this->assertSame(ReleaseStepStatus::Active, $step->fresh()->status);
        $this->assertSame(0, ReleaseEvent::count());
    }

    public function test_the_last_step_of_the_chain_hands_the_release_over_as_delivered(): void
    {
        $release = $this->releaseInProgress(steps: 1);
        $step = $release->steps->first();

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step]);

        // La schermata annuncia la consegna gia prima dell'invio: chiudere questo
        // step non passa il flusso a nessuno, lo conclude.
        $component->assertSee(__('releases.step_hands_over_last'));
        // E dice che la chiusura e definitiva: e la frase che tiene fede a FR-019.
        $component->assertSee(__('releases.step_closing_is_final'));

        foreach ($this->validValuesFor($step) as $field => $value) {
            $component->set('values.'.$field, $value);
        }

        $component->call('close')
            ->assertHasNoErrors()
            ->assertSee(__('releases.step_release_completed_heading'));

        $this->assertSame(ReleaseStatus::Completed, $release->fresh()->status);
        $this->assertSame(ReleaseStepStatus::Completed, $step->fresh()->status);

        /*
         * Dopo la consegna la pagina non offre piu alcun comando sullo step (AC 7):
         * ne la chiusura ne il salvataggio, e nessun testo lascia intendere che il
         * passaggio possa essere riaperto.
         */
        $component->assertDontSee(__('releases.step_close_action'));
        $component->assertDontSee(__('releases.step_save_action'));
        $component->assertSee(__('releases.step_release_completed_notice', [
            'release' => $release->label,
            'date' => $release->fresh()->completed_at->format('d/m/Y H:i'),
        ]));
    }

    public function test_a_blocked_step_offers_no_form_and_says_who_is_awaited(): void
    {
        $release = $this->releaseInProgress();
        $active = $release->steps->first();
        $blocked = $release->steps->get(1);

        $this->actingAs($blocked->assignedUser);

        $response = $this->get(route('releases.step', $blocked))->assertOk();

        $response->assertSee(__('releases.step_blocked_heading'));
        $response->assertSee(__('releases.step_blocked_waiting', [
            'position' => $active->position,
            'step' => $active->name,
            'name' => $active->assignedUser->name,
        ]));

        $response->assertDontSee(__('releases.step_close_action'));
        $response->assertDontSee(__('releases.step_save_action'));
    }

    public function test_a_completed_step_is_read_only_and_shows_the_provided_values(): void
    {
        $release = $this->releaseInProgress();
        $step = $release->steps->first();

        $optional = $step->fields->firstWhere('is_required', false);

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step]);

        foreach ($this->validValuesFor($step) as $field => $value) {
            $component->set('values.'.$field, $value);
        }

        // Il campo facoltativo resta vuoto: la chiusura riesce comunque, ed e cio
        // che il dettaglio deve poter dire come "non fornito".
        $component->set('values.'.$optional->id, '')
            ->call('close')
            ->assertHasNoErrors();

        $response = $this->get(route('releases.step', $step))->assertOk();

        $response->assertSee(__('releases.step_completed_heading'));
        $response->assertSee(__('releases.step_values_heading'));
        $response->assertSee('2.4.0');
        $response->assertSee(__('releases.step_value_confirmed'));
        // Il campo opzionale non compilato dice "non fornito", non una riga vuota.
        $response->assertSee(__('releases.step_value_not_provided'));

        $response->assertDontSee(__('releases.step_close_action'));
    }

    public function test_a_stored_link_that_is_not_http_is_shown_as_text_and_not_as_a_link(): void
    {
        /*
         * `WellFormedLink` garantisce lo schema in scrittura, ma una riga arrivata da
         * un import o da una correzione a mano sul database non passa da quella
         * regola. Un `javascript:` reso come `href` sarebbe una superficie offerta a
         * chi consulta lo storico dei rilasci.
         */
        $release = $this->releaseInProgress();
        $step = $release->steps->first();
        $link = $step->fields->firstWhere('type', FieldType::Link);

        $step->update([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $step->assigned_user_id,
            'completed_at' => now(),
        ]);

        // Scrittura di massa dal query builder: aggira i modelli, come farebbe un
        // import.
        ReleaseStepField::query()->whereKey($link->id)->update(['value' => 'javascript:alert(1)']);

        $this->actingAs($step->assignedUser);

        $response = $this->get(route('releases.step', $step->fresh()))->assertOk();

        $response->assertSee('javascript:alert(1)');
        $response->assertDontSee('href="javascript:alert(1)"', escape: false);
    }

    public function test_the_screen_does_not_query_per_field(): void
    {
        // Uno step del template dimostrativo ha quattordici campi: senza eager
        // loading il costo crescerebbe con la lunghezza del form, ed e il rischio
        // strutturale che il PRD indica per le catene annidate.
        $short = $this->releaseInProgress(fieldsPerStep: 1);
        $long = $this->releaseInProgress(fieldsPerStep: 14);

        $this->actingAs(User::factory()->admin()->create());

        $shortCost = $this->queriesWhile(
            fn () => $this->get(route('releases.step', $short->steps->first()))->assertOk()
        );

        $longCost = $this->queriesWhile(
            fn () => $this->get(route('releases.step', $long->steps->first()))->assertOk()
        );

        $this->assertSame(
            $shortCost,
            $longCost,
            "La schermata e costata {$shortCost} query su un campo e {$longCost} su quattordici: manca un eager loading."
        );
    }

    /**
     * Numero di query eseguite durante la chiamata.
     */
    private function queriesWhile(callable $work): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $work();

        return $count;
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
     * Release in corso: il primo step attivo, gli altri bloccati, con un campo per
     * ciascuno dei quattro tipi piu eventuali campi aggiuntivi.
     */
    private function releaseInProgress(int $steps = 2, int $fieldsPerStep = 4): Release
    {
        $release = Release::factory()->create();

        for ($position = 1; $position <= $steps; $position++) {
            $step = ReleaseStep::factory()->for($release)->create([
                'position' => $position,
                'status' => $position === 1 ? ReleaseStepStatus::Active : ReleaseStepStatus::Blocked,
                'assigned_user_id' => User::factory()->create()->id,
            ]);

            $types = [FieldType::ShortText, FieldType::Link, FieldType::Confirmation, FieldType::LongText];

            for ($field = 1; $field <= $fieldsPerStep; $field++) {
                $type = $types[($field - 1) % count($types)];

                ReleaseStepField::factory()->for($step)->create([
                    'position' => $field,
                    'type' => $type,
                    // L'ultimo dei quattro tipi resta opzionale: serve a dimostrare
                    // che un campo facoltativo vuoto non impedisce la chiusura.
                    'is_required' => $type !== FieldType::LongText,
                    'help_text' => $type === FieldType::Confirmation ? null : 'Come compare nel tag di rilascio.',
                ]);
            }
        }

        return $release->load('project', 'steps.fields', 'steps.assignedUser');
    }
}
