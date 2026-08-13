<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il dettaglio della release: cosa mostra di ciascuno step, cosa non mostra, e a chi.
 *
 * Il criterio piu facile da tradire e l'ultimo dell'elenco: uno step **bloccato**
 * non deve mostrare alcun campo compilabile ne valore. Cio che non e ancora stato
 * chiesto non ha nulla da dire, e anticiparlo lascerebbe credere che qualcuno abbia
 * gia risposto.
 *
 * Il secondo e la lettura aperta: un membro estraneo alla catena deve poter aprire
 * il dettaglio in sola lettura. Su uno strumento senza notifiche, sapere dove un
 * rilascio e fermo non e un privilegio.
 */
class ReleaseDetailScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_header_states_the_release_the_project_and_who_started_it(): void
    {
        $starter = User::factory()->admin()->create(['name' => 'Francesco Giarola']);

        $release = $this->releaseInProgress(startedBy: $starter);
        $release->update(['started_at' => now()->subDays(1)]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk();

        $response->assertSee('Portale Clienti');
        $response->assertSee('v2.4.0');
        $response->assertSee(ReleaseStatus::InProgress->label());
        // Il template e citato come **provenienza**: la nota accanto dice che la
        // catena mostrata e lo snapshot congelato, non la definizione di adesso.
        $response->assertSee($release->workflowTemplate->name);
        $response->assertSee('Francesco Giarola');
        $response->assertSee($release->fresh()->started_at->format('d/m/Y H:i'));
    }

    public function test_the_chain_follows_the_frozen_order_with_the_state_of_each_step(): void
    {
        $release = $this->releaseInProgress();

        $body = (string) $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            ->getContent();

        $names = $release->steps->map(fn (ReleaseStep $step): string => e($step->name))->all();

        $this->assertCount(5, $names, 'La catena di prova deve avere cinque step distinti.');

        // I nomi devono comparire **davvero**: senza questa verifica `strpos` tornerebbe
        // `false` per tutti, il confronto sull'ordine sarebbe fra cinque zeri e il test
        // passerebbe su una pagina che non rende alcuno step.
        foreach ($names as $name) {
            $this->assertStringContainsString($name, $body);
        }

        // L'ordine e parte dell'informazione: `assertSee` non guarda la posizione,
        // quindi senza questo confronto togliere l'ordinamento lascerebbe la suite
        // verde su una catena mostrata a caso.
        $positions = array_map(fn (string $name): int => (int) strpos($body, $name), $names);
        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'La catena deve seguire l\'ordine congelato dello snapshot.');

        $this->assertStringContainsString(ReleaseStepStatus::Completed->label(), $body);
        $this->assertStringContainsString(ReleaseStepStatus::Active->label(), $body);
        $this->assertStringContainsString(ReleaseStepStatus::Blocked->label(), $body);
    }

    public function test_every_step_shows_the_frozen_role_and_the_resolved_responsible(): void
    {
        $release = $this->releaseInProgress();
        $active = $release->steps->firstWhere('position', 2);

        // Rinominare il ruolo nel catalogo non deve riscrivere un rilascio in corso:
        // la schermata legge `role_name`, congelato all'avvio.
        Role::query()->whereKey($active->role_id)->update(['name' => 'Quality Assurance']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk();

        $response->assertSee(__('releases.detail_step_owner', [
            'role' => 'QA',
            'name' => $active->assignedUser->name,
        ]));
        $response->assertDontSee('Quality Assurance');
    }

    public function test_a_completed_step_shows_the_values_the_author_and_the_closing_instant(): void
    {
        $release = $this->releaseInProgress();
        $completed = $release->steps->firstWhere('position', 1);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk();

        $response->assertSee(__('releases.detail_step_closed_at', [
            'name' => $completed->completedBy->name,
            'date' => $completed->completed_at->format('d/m/Y H:i'),
        ]));

        $response->assertSee('2.4.0');
        $response->assertSee('https://ci.gruppoexcellence.com/pipeline/4471');
        // Il campo lasciato vuoto compare come "non fornito": una riga assente non
        // distinguerebbe "non richiesto" da "non risposto".
        $response->assertSee(__('releases.step_value_not_provided'));
    }

    public function test_a_blocked_step_shows_no_fillable_field_and_no_value(): void
    {
        $release = $this->releaseInProgress();
        $blocked = $release->steps->firstWhere('position', 3);

        $field = ReleaseStepField::factory()->for($blocked)->shortText()->create(['label' => 'Esito del backup']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk();

        // Lo step compare — la catena e completa — ma di cio che gli sara chiesto
        // non mostra nulla: ne l'etichetta del campo ne un posto in cui rispondere.
        $response->assertSee($blocked->name);
        $response->assertSee(__('releases.detail_step_unlocks_after', ['position' => 2]));
        $response->assertDontSee($field->label);

        /*
         * "Non fornito" compare una volta sola in tutta la pagina, e appartiene al
         * campo facoltativo dello step **chiuso**: se comparisse anche qui, lo step
         * bloccato starebbe dichiarando una risposta mancante a una domanda che
         * nessuno ha ancora posto.
         */
        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), __('releases.step_value_not_provided')),
            'Solo lo step chiuso puo dichiarare un campo non fornito.'
        );
    }

    public function test_the_last_blocked_step_says_that_its_closure_concludes_the_release(): void
    {
        $release = $this->releaseInProgress();

        $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            ->assertSee(__('releases.detail_step_unlocks_last'));
    }

    public function test_the_open_command_appears_to_the_responsible_and_to_an_administrator(): void
    {
        $release = $this->releaseInProgress();
        $active = $release->steps->firstWhere('position', 2);

        foreach ([$active->assignedUser, User::factory()->admin()->create()] as $allowed) {
            $this->actingAs($allowed)
                ->get(route('releases.show', $release))
                ->assertOk()
                ->assertSee(route('releases.step', $active))
                ->assertSee(__('releases.step_open_action'));
        }
    }

    public function test_the_open_command_does_not_appear_to_a_member_who_is_not_responsible(): void
    {
        $release = $this->releaseInProgress();
        $active = $release->steps->firstWhere('position', 2);

        // Nascondere il comando non e autorizzazione — quella e nella Policy, e la
        // schermata di chiusura la riapplica — ma mostrarlo a chi verrebbe rifiutato
        // sarebbe una promessa che la pagina non mantiene.
        $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee(route('releases.step', $active));
    }

    public function test_a_link_field_becomes_a_link_and_a_malformed_scheme_stays_text(): void
    {
        $release = $this->releaseInProgress();
        $completed = $release->steps->firstWhere('position', 1);

        // `WellFormedLink` garantisce lo schema in scrittura, ma una riga arrivata da
        // un import o da una correzione a mano sul database non passa da quella
        // regola: un `javascript:` reso come href sarebbe una superficie offerta
        // proprio a chi consulta lo storico.
        $malformed = ReleaseStepField::factory()->for($completed)->link()
            ->create(['label' => 'Link manomesso', 'value' => 'javascript:alert(1)']);

        $body = (string) $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="https://ci.gruppoexcellence.com/pipeline/4471"', $body);
        $this->assertStringNotContainsString('href="javascript:alert(1)"', $body);
        // Resta comunque leggibile come testo: il valore c'e, ma non e cliccabile.
        $this->assertStringContainsString(e($malformed->value), $body);
    }

    public function test_a_filled_confirmation_field_is_rendered_as_an_affirmative_outcome(): void
    {
        $release = $this->releaseInProgress();

        $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            ->assertSee(__('releases.step_value_confirmed'));
    }

    public function test_a_member_with_no_step_assigned_reads_the_detail_anyway(): void
    {
        $release = $this->releaseInProgress();

        // Estraneo alla catena: nessuno step assegnato, nessuna chiusura a suo nome.
        $stranger = User::factory()->member()->create();

        $response = $this->actingAs($stranger)
            ->get(route('releases.show', $release))
            ->assertOk();

        $response->assertSee(__('releases.detail_chain_heading'));
        $response->assertSee(__('releases.detail_meta_heading'));

        // Sola lettura: nessun comando di apertura, su nessuno step.
        $release->steps->each(fn (ReleaseStep $step) => $response->assertDontSee(route('releases.step', $step)));
    }

    public function test_a_guest_is_redirected_to_the_login(): void
    {
        $release = $this->releaseInProgress();

        $this->get(route('releases.show', $release))->assertRedirect(route('login'));
    }

    public function test_a_concluded_release_shows_the_whole_chain_completed_and_no_open_command(): void
    {
        $release = $this->releaseCompleted();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk();

        $response->assertSee(__('releases.detail_summary_completed', [
            'date' => $release->completed_at->format('d/m/Y H:i'),
        ]));
        $response->assertSee(ReleaseStatus::Completed->label());
        $response->assertSee(__('releases.detail_meta_completed_steps_value', ['completed' => 3, 'total' => 3]));
        $response->assertDontSee(__('releases.step_open_action'));
        $response->assertDontSee(ReleaseStepStatus::Blocked->label());
    }

    public function test_the_summary_counts_the_position_of_the_active_step_and_names_who_holds_it(): void
    {
        $release = $this->releaseInProgress();
        $active = $release->steps->firstWhere('position', 2);

        $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            // Regione live come in "i miei step": la riga cambia quando la catena
            // avanza, e chi non vede lo schermo deve poterlo sentire.
            ->assertSee('aria-live="polite"', escape: false)
            ->assertSee(__('releases.detail_summary_in_progress', [
                'position' => 2,
                'total' => 5,
                'name' => $active->assignedUser->name,
            ]));
    }

    public function test_the_page_keeps_a_single_h1_and_the_two_sections_as_h2(): void
    {
        $release = $this->releaseInProgress();

        $body = (string) $this->actingAs(User::factory()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            substr_count($body, '<h1'),
            'La pagina deve avere un solo h1: catena e dati della release sono h2.'
        );
    }

    /**
     * Release in corso di cinque step: il primo chiuso con i quattro tipi di campo —
     * uno lasciato non fornito — il secondo attivo, i restanti bloccati.
     *
     * E la forma reale della schermata: uno step per stato, e i campi solo dove sono
     * stati chiesti.
     */
    private function releaseInProgress(?User $startedBy = null): Release
    {
        $release = Release::factory()
            ->for(Project::factory()->withTemplate()->create(['name' => 'Portale Clienti']))
            ->create([
                'label' => 'v2.4.0',
                'started_by' => ($startedBy ?? User::factory()->admin()->create())->id,
            ]);

        $first = ReleaseStep::factory()->for($release)->completed()->create([
            'position' => 1,
            'role_name' => 'Dev Lead',
        ]);

        // I quattro tipi, con il valore coerente col proprio tipo: un link con
        // "lorem ipsum" descriverebbe uno stato che la chiusura non puo produrre.
        ReleaseStepField::factory()->for($first)->shortText()->filled()->create();
        ReleaseStepField::factory()->for($first)->link()->filled()->create();
        ReleaseStepField::factory()->for($first)->confirmation()->filled()->create();
        // Facoltativo e lasciato vuoto: e il caso "non fornito".
        ReleaseStepField::factory()->for($first)->longText()->optional()->create();

        $active = ReleaseStep::factory()->for($release)->active()->create([
            'position' => 2,
            'role_name' => 'QA',
        ]);

        ReleaseStepField::factory()->for($active)->count(2)->create();

        foreach ([3, 4, 5] as $position) {
            ReleaseStep::factory()->for($release)->blocked()->create(['position' => $position]);
        }

        return $release->load('steps.assignedUser', 'steps.completedBy');
    }

    /**
     * Release conclusa: catena tutta completata, nessuno step da aprire.
     */
    private function releaseCompleted(): Release
    {
        $release = Release::factory()
            ->for(Project::factory()->withTemplate()->create(['name' => 'App Preventivi']))
            ->completed()
            ->create(['label' => 'v1.9.2']);

        for ($position = 1; $position <= 3; $position++) {
            ReleaseStep::factory()->for($release)->completed()->create(['position' => $position]);
        }

        return $release->load('steps');
    }
}
