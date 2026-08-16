<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\CloseStep;
use App\Actions\Releases\RecordUnauthorizedStepAttempt;
use App\Actions\Releases\StartRelease;
use App\Enums\FieldType;
use App\Enums\ReleaseEventAction;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il registro come lo si consulta.
 *
 * Il registro di prova e costruito passando dalle **Action reali** e non dalle sole
 * factory: un insieme assemblato a mano dimostrerebbe la resa di righe che nessun
 * percorso applicativo scrive, e proprio il registro non puo permetterselo — e la
 * pagina che si apre quando qualcuno contesta come e andato un rilascio.
 *
 * Il criterio piu facile da tradire e la visibilita differenziata: non basta che il
 * tentativo non autorizzato sparisca dalla vista di un membro, deve sparire **anche
 * ogni traccia della sua esistenza**. Un conteggio di "voci nascoste" sarebbe il
 * peggiore dei due mondi.
 */
class ReleaseLogScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_register_shows_the_transitions_in_chronological_order(): void
    {
        $release = $this->releaseWithAClosedStep();

        $body = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('releases.log', $release))->assertOk()->getContent();

        $started = strpos($body, ReleaseEventAction::ReleaseStarted->label());
        $completed = strpos($body, ReleaseEventAction::StepCompleted->label());
        $activated = strpos($body, ReleaseEventAction::StepActivated->label());

        $this->assertNotFalse($started);
        $this->assertNotFalse($completed);
        $this->assertNotFalse($activated);

        // Crescente: l'avvio in cima, cio che e seguito sotto.
        $this->assertLessThan($completed, $started, 'L\'avvio non compare per primo.');

        /*
         * I due eventi della chiusura nascono nella **stessa transazione**, quindi
         * con lo stesso istante al secondo: senza lo spareggio sull'identificativo
         * la cronologia potrebbe dire che il flusso e passato al responsabile
         * successivo prima che lo step precedente si chiudesse.
         */
        $this->assertLessThan(
            $activated,
            $completed,
            'L\'attivazione dello step successivo compare prima della chiusura che l\'ha prodotta.'
        );
    }

    public function test_every_entry_names_its_actor_and_its_instant(): void
    {
        $starter = User::factory()->admin()->create(['name' => 'Francesco Giarola']);
        $release = $this->releaseStarted($starter);

        $event = ReleaseEvent::query()->where('release_id', $release->id)->firstOrFail();

        $response = $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.log', $release))->assertOk();

        $response->assertSee(__('releases.log_actor', ['name' => 'Francesco Giarola']));
        $response->assertSee($event->created_at->format('d/m/Y H:i'));
        // Il valore macchina accanto a quello leggibile: la stessa riga serve a chi
        // legge e a chi la estrae.
        $response->assertSee($event->created_at->toIso8601String(), false);
    }

    public function test_the_start_entry_says_it_concerns_the_release_and_not_a_step(): void
    {
        $release = $this->releaseStarted();

        // L'avvio non ha uno step: la riga lo dichiara invece di lasciare uno spazio
        // vuoto da interpretare come dato mancante.
        $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.log', $release))
            ->assertOk()
            ->assertSee(__('releases.log_on_release'));
    }

    public function test_an_entry_about_a_step_names_position_and_step(): void
    {
        $release = $this->releaseWithAClosedStep();
        $first = $release->steps()->where('position', 1)->firstOrFail();

        $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.log', $release))
            ->assertOk()
            ->assertSee(__('releases.log_on_step', [
                'position' => $first->position,
                'step' => $first->name,
            ]));
    }

    public function test_an_administrator_sees_the_unauthorized_attempt(): void
    {
        [$release, $intruder] = $this->releaseWithAnUnauthorizedAttempt();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('releases.log', $release))->assertOk();

        $response->assertSee(ReleaseEventAction::UnauthorizedAttempt->label());
        $response->assertSee($intruder->name);
        // Chi la vede deve sapere che gli altri non la vedono: senza la nota
        // potrebbe citarla dando per scontato che sia visibile a tutti.
        $response->assertSee(__('releases.log_admin_only'));
    }

    public function test_a_member_sees_neither_the_attempt_nor_any_trace_of_it(): void
    {
        [$release, $intruder] = $this->releaseWithAnUnauthorizedAttempt();

        $response = $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.log', $release))->assertOk();

        $response->assertDontSee(ReleaseEventAction::UnauthorizedAttempt->label());
        $response->assertDontSee($intruder->name);
        // Nessun conteggio di voci nascoste, nessuna nota: dichiarare l'esistenza
        // di righe che non si mostrano e il peggiore dei due mondi.
        $response->assertDontSee(__('releases.log_admin_only'));
        $response->assertDontSee(__('releases.log_ability_close'));

        // Le transizioni di processo restano visibili: la restrizione riguarda i
        // tentativi, non il registro.
        $response->assertSee(ReleaseEventAction::ReleaseStarted->label());
    }

    public function test_the_page_declares_that_the_register_cannot_be_altered(): void
    {
        $release = $this->releaseStarted();

        // Chi consulta il registro per ricostruire un rilascio contestato deve
        // sapere che vale come prova senza dover leggere il codice.
        $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.log', $release))
            ->assertOk()
            ->assertSee(__('releases.log_append_only'));
    }

    public function test_the_register_is_reachable_from_the_release_detail(): void
    {
        $release = $this->releaseStarted();

        $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.show', $release))
            ->assertOk()
            ->assertSee(route('releases.log', $release), false);
    }

    public function test_every_action_of_the_vocabulary_is_rendered_with_an_icon_and_a_word(): void
    {
        $release = Release::factory()->create();
        $step = ReleaseStep::factory()->for($release)->create(['position' => 1]);

        // Una voce per **ogni** caso dell'enum: la mappa delle icone e un array
        // indicizzato per valore, quindi un caso aggiunto senza icona non
        // produrrebbe un difetto grafico ma una pagina che non si apre. Il
        // vocabolario e dichiarato destinato a crescere (FR-020, l'annullamento),
        // e questo test e il posto in cui quella crescita si presenta.
        foreach (ReleaseEventAction::cases() as $action) {
            ReleaseEvent::factory()->for($release)->create([
                'action' => $action,
                'release_step_id' => $step->id,
            ]);
        }

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('releases.log', $release))->assertOk();

        foreach (ReleaseEventAction::cases() as $action) {
            // La parola: lo stato non e mai reso dal solo colore ne dalla sola icona.
            $response->assertSee($action->label());
        }
    }

    public function test_a_guest_is_redirected_to_the_login(): void
    {
        $release = $this->releaseStarted();

        $this->get(route('releases.log', $release))->assertRedirect(route('login'));
    }

    /**
     * Release avviata dall'Action reale: il registro nasce con la sua prima riga.
     */
    private function releaseStarted(?User $starter = null): Release
    {
        return app(StartRelease::class)->handle(
            $this->projectReadyToRelease(),
            'v2.4.0',
            $starter ?? User::factory()->admin()->create(),
        );
    }

    /**
     * Release con il primo step chiuso: tre righe nel registro, due delle quali
     * scritte nella stessa transazione.
     */
    private function releaseWithAClosedStep(): Release
    {
        $release = $this->releaseStarted();

        $step = $release->steps()->with('fields')->where('position', 1)->firstOrFail();

        app(CloseStep::class)->handle(
            $step,
            $step->fields->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => $this->valueFor($field),
            ])->all(),
            $step->assignedUser,
        );

        return $release->refresh();
    }

    /**
     * Release su cui qualcuno ha provato a chiudere uno step non suo.
     *
     * @return array{0: Release, 1: User}
     */
    private function releaseWithAnUnauthorizedAttempt(): array
    {
        $release = $this->releaseStarted();
        $step = $release->steps()->where('position', 1)->firstOrFail();

        $intruder = User::factory()->member()->create(['name' => 'Estraneo Al Rilascio']);

        app(RecordUnauthorizedStepAttempt::class)->handle($step, $intruder, 'close');

        return [$release->refresh(), $intruder];
    }

    /**
     * Valore coerente col tipo del campo: un link con "lorem ipsum" descriverebbe
     * uno stato che la chiusura non puo produrre.
     */
    private function valueFor(ReleaseStepField $field): string
    {
        return match ($field->type) {
            FieldType::Link => 'https://esempio.test/rilascio',
            FieldType::Confirmation => '1',
            default => 'Valore fornito in fase di chiusura.',
        };
    }

    private function projectReadyToRelease(): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $roles = Role::factory()->count(3)->create();

        foreach ($roles as $position => $role) {
            $step = StepDefinition::factory()->for($template)->create([
                'position' => $position + 1,
                'role_id' => $role->id,
            ]);

            FieldDefinition::factory()->count(2)->for($step)->create();
        }

        $project = Project::factory()->withTemplate($template)->create();

        foreach ($roles as $role) {
            ProjectRoleAssignment::factory()->create([
                'project_id' => $project->id,
                'role_id' => $role->id,
                'user_id' => User::factory()->create()->id,
            ]);
        }

        return $project->fresh();
    }
}
