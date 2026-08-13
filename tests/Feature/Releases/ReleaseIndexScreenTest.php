<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'elenco delle release: cosa mostra di ciascuna sezione, come filtra, e a chi.
 *
 * Il criterio piu facile da tradire e quello che nessuna schermata mostra: lo
 * storico e consultabile **a tempo indeterminato**. Basta un `where` di comodo
 * sull'ultimo anno — messo per far sembrare la pagina piu veloce — perche un
 * rilascio del 2024 sparisca senza che nulla lo dichiari. Il test lo tiene fermo.
 *
 * Il secondo e l'ordinamento: le due sezioni hanno due nozioni diverse di
 * "recente" — l'avvio per quelle aperte, la consegna per quelle concluse — e
 * uniformarle e la scorciatoia che sembra una pulizia.
 */
class ReleaseIndexScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_open_section_names_the_current_step_and_who_holds_the_flow(): void
    {
        $release = $this->releaseInProgress(
            project: 'Portale Clienti',
            label: 'v2.4.0',
            waitingFor: 'Marta Bellini',
        );

        $response = $this->actingAs($this->member())->get(route('releases.index'))->assertOk();

        $response->assertSee('Portale Clienti');
        $response->assertSee('v2.4.0');
        $response->assertSee(ReleaseStatus::InProgress->label());
        // Posizione **e** lunghezza della catena: "step 2" da solo non dice se
        // manchi un passaggio o quattro.
        $response->assertSee('2 di 3 — Verifica funzionale');
        $response->assertSee('Marta Bellini', false);

        $this->assertSame(ReleaseStatus::InProgress, $release->fresh()->status);
    }

    public function test_the_history_names_who_delivered_and_when(): void
    {
        $release = $this->releaseCompleted(
            project: 'Gestionale Magazzino',
            label: 'v1.9.1',
            deliveredBy: 'Davide Rossi',
        );

        $response = $this->actingAs($this->member())->get(route('releases.index'))->assertOk();

        $response->assertSee('Gestionale Magazzino');
        $response->assertSee('v1.9.1');
        $response->assertSee(ReleaseStatus::Completed->label());
        $response->assertSee('Davide Rossi');
        $response->assertSee($release->completed_at->format('d/m/Y H:i'));
    }

    public function test_the_history_has_no_implicit_date_limit(): void
    {
        // Due anni fa: nessun filtro temporale deve poterlo nascondere. E il
        // criterio che si rompe piu facilmente e di cui ci si accorge piu tardi —
        // la pagina continua a sembrare sana, solo con meno righe.
        $old = $this->releaseCompleted(project: 'Portale Clienti', label: 'v1.0.0');
        $old->forceFill([
            'started_at' => now()->subYears(2)->subDay(),
            'completed_at' => now()->subYears(2),
        ])->save();

        $this->actingAs($this->member())
            ->get(route('releases.index'))
            ->assertOk()
            ->assertSee('v1.0.0');
    }

    public function test_the_open_section_is_ordered_from_the_most_recently_started(): void
    {
        $this->releaseInProgress(label: 'v1.0.0')->forceFill(['started_at' => now()->subDays(9)])->save();
        $this->releaseInProgress(label: 'v3.0.0')->forceFill(['started_at' => now()->subHours(2)])->save();
        $this->releaseInProgress(label: 'v2.0.0')->forceFill(['started_at' => now()->subDays(3)])->save();

        $body = (string) $this->actingAs($this->member())
            ->get(route('releases.index'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'v2.0.0'),
            strpos($body, 'v3.0.0'),
            'La release avviata piu di recente non compare per prima.'
        );
        $this->assertLessThan(strpos($body, 'v1.0.0'), strpos($body, 'v2.0.0'));
    }

    public function test_the_history_is_ordered_by_delivery_and_not_by_start(): void
    {
        /*
         * Le due release si incrociano di proposito: quella avviata **prima** e
         * stata consegnata **dopo**. Ordinare lo storico su `started_at` — la
         * scorciatoia che uniforma le due sezioni — le invertirebbe, e su un
         * insieme di prova senza incroci il difetto resterebbe invisibile.
         */
        $this->releaseCompleted(label: 'v1.0.0')->forceFill([
            'started_at' => now()->subDays(30),
            'completed_at' => now()->subDay(),
        ])->save();

        $this->releaseCompleted(label: 'v2.0.0')->forceFill([
            'started_at' => now()->subDays(10),
            'completed_at' => now()->subDays(5),
        ])->save();

        $body = (string) $this->actingAs($this->member())
            ->get(route('releases.index'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'v2.0.0'),
            strpos($body, 'v1.0.0'),
            'Lo storico non e ordinato per istante di consegna.'
        );
    }

    public function test_the_status_filter_shows_one_section_at_a_time(): void
    {
        $this->releaseInProgress(label: 'v2.4.0');
        $this->releaseCompleted(label: 'v2.3.0');

        $member = $this->member();

        $onlyOpen = $this->actingAs($member)
            ->get(route('releases.index', ['stato' => ReleaseStatus::InProgress->value]))->assertOk();
        $onlyOpen->assertSee('v2.4.0');
        $onlyOpen->assertDontSee('v2.3.0');

        $onlyHistory = $this->actingAs($member)
            ->get(route('releases.index', ['stato' => ReleaseStatus::Completed->value]))->assertOk();
        $onlyHistory->assertSee('v2.3.0');
        $onlyHistory->assertDontSee('v2.4.0');

        // Il terzo valore e il ritorno alla vista d'insieme: senza, non esisterebbe
        // un modo per rivedere entrambe le sezioni dopo aver filtrato.
        $both = $this->actingAs($member)->get(route('releases.index'))->assertOk();
        $both->assertSee('v2.4.0');
        $both->assertSee('v2.3.0');
    }

    public function test_an_unknown_status_in_the_address_shows_everything_instead_of_failing(): void
    {
        $this->releaseInProgress(label: 'v2.4.0');
        $this->releaseCompleted(label: 'v2.3.0');

        // Il filtro arriva dalla barra dell'indirizzo, cioe da un input non fidato:
        // un valore fuori vocabolario non deve far fallire il cast a enum dentro
        // la query.
        $response = $this->actingAs($this->member())
            ->get(route('releases.index', ['stato' => 'qualsiasi-cosa']))->assertOk();

        $response->assertSee('v2.4.0');
        $response->assertSee('v2.3.0');
    }

    public function test_the_project_filter_restricts_both_sections(): void
    {
        // Lo **stesso** progetto per le due release che devono restare: filtrare per
        // identificativo su due righe omonime dimostrerebbe il contrario di quello
        // che il test crede di verificare.
        $wanted = Project::factory()->withTemplate()->create(['name' => 'Portale Clienti']);

        $this->releaseInProgress(project: $wanted, label: 'v2.4.0');
        $this->releaseCompleted(project: $wanted, label: 'v2.3.0');
        $this->releaseInProgress(label: 'v9.9.9');

        $response = $this->actingAs($this->member())
            ->get(route('releases.index', ['progetto' => $wanted->id]))->assertOk();

        $response->assertSee('v2.4.0');
        $response->assertSee('v2.3.0');
        $response->assertDontSee('v9.9.9');
    }

    public function test_the_empty_state_says_which_filter_produces_it(): void
    {
        $project = Project::factory()->withTemplate()->create(['name' => 'Portale Clienti']);
        $this->releaseCompleted(project: $project, label: 'v2.3.0');

        // Il progetto ha solo release concluse: la sezione in corso e vuota, e lo
        // stato vuoto deve nominare il filtro invece di dire "nessun risultato".
        $this->actingAs($this->member())
            ->get(route('releases.index', ['progetto' => $project->id]))
            ->assertOk()
            ->assertSee(__('releases.index_empty_filtered', ['project' => 'Portale Clienti']));
    }

    public function test_the_empty_list_explains_when_a_release_will_appear(): void
    {
        $this->actingAs($this->member())
            ->get(route('releases.index'))
            ->assertOk()
            ->assertSee(__('releases.index_empty_in_progress_explained'))
            ->assertSee(__('releases.index_empty_completed_explained'));
    }

    public function test_a_member_who_is_responsible_for_nothing_can_still_consult_the_list(): void
    {
        $this->releaseInProgress(label: 'v2.4.0');

        // Un membro **estraneo**: nessuno step assegnato, nessun coinvolgimento.
        // Senza notifiche, sapere quali rilasci sono aperti non e un privilegio.
        $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.index'))
            ->assertOk()
            ->assertSee('v2.4.0');
    }

    public function test_a_guest_is_redirected_to_the_login(): void
    {
        $this->get(route('releases.index'))->assertRedirect(route('login'));
    }

    public function test_the_project_filter_offers_only_projects_that_have_releases(): void
    {
        $this->releaseInProgress(project: 'Portale Clienti', label: 'v2.4.0');
        Project::factory()->withTemplate()->create(['name' => 'Progetto Senza Rilasci']);

        // Un progetto senza rilasci e un'opzione che filtra verso il vuoto: offrirla
        // e un modo per far sembrare rotta una schermata sana.
        $this->actingAs($this->member())
            ->get(route('releases.index'))
            ->assertOk()
            ->assertDontSee('Progetto Senza Rilasci');
    }

    /**
     * Release in corso, ferma sul secondo di tre step.
     */
    private function releaseInProgress(
        Project|string $project = 'Portale Clienti',
        string $label = 'v2.4.0',
        string $waitingFor = 'Marta Bellini',
    ): Release {
        $release = Release::factory()
            ->for($this->project($project))
            ->create(['label' => $label]);

        /*
         * `forceFill` e non `update`: `completed_at` e `completed_by` non sono
         * assegnabili in massa — le scrive solo `CloseStep` — e un `update` le
         * lascerebbe cadere **in silenzio**, riportando l'attesa a `started_at`
         * (vedi `.ai/rules/tests.md`).
         */
        ReleaseStep::factory()->for($release)->completed()->create([
            'position' => 1,
            'name' => 'Preparazione del codice',
        ])->forceFill(['completed_at' => now()->subHours(4)])->save();

        ReleaseStep::factory()->for($release)->active()->create([
            'position' => 2,
            'name' => 'Verifica funzionale',
            'assigned_user_id' => User::factory()->create(['name' => $waitingFor])->id,
        ]);

        ReleaseStep::factory()->for($release)->blocked()->create(['position' => 3]);

        return $release;
    }

    /**
     * Release conclusa, con tutta la catena chiusa.
     */
    private function releaseCompleted(
        Project|string $project = 'Portale Clienti',
        string $label = 'v2.3.0',
        string $deliveredBy = 'Davide Rossi',
    ): Release {
        $deliverer = User::factory()->create(['name' => $deliveredBy]);

        $release = Release::factory()
            ->for($this->project($project))
            ->create(['label' => $label]);

        ReleaseStep::factory()->for($release)->completed()->count(2)->create();

        $release->forceFill([
            'status' => ReleaseStatus::Completed,
            'completed_by' => $deliverer->id,
            'completed_at' => now(),
        ])->save();

        return $release;
    }

    /**
     * Progetto gia esistente, oppure uno nuovo con quel nome.
     *
     * Due release che devono comparire sotto lo stesso filtro devono stare sullo
     * **stesso** progetto: due righe omonime hanno identificativi diversi, e un
     * test che le confondesse verificherebbe il contrario di quello che dichiara.
     */
    private function project(Project|string $project): Project
    {
        return $project instanceof Project
            ? $project
            : Project::factory()->withTemplate()->create(['name' => $project]);
    }

    private function member(): User
    {
        return User::factory()->member()->create();
    }
}
