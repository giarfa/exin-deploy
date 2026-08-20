<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseStepStatus;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Flusso di avvio dall'interfaccia: cosa vede chi avvia prima del tentativo,
 * cosa ottiene dopo, e come vengono resi i rifiuti.
 *
 * Verifiche manuali che questa suite non copre (bar `NORMAL`: nessun browser, nessuna
 * matrice di viewport) e che vanno rifatte quando il modulo di avvio cambia forma:
 *
 * 1. A 375 px i select dei responsabili non producono scorrimento orizzontale e
 *    restano raggiungibili da tastiera nell'ordine in cui appaiono.
 * 2. A 375 / 768 / 1280 px il pulsante di invio si abilita nel momento in cui viene
 *    scelto l'ultimo override mancante, senza ricaricare la pagina.
 * 3. Il riquadro del motivo bloccante viene annunciato da un lettore di schermo
 *    (`aria-live`, come i riquadri di conferma e di rifiuto).
 */
class StartReleaseScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_page_shows_the_preconditions_before_the_attempt(): void
    {
        $project = $this->projectReadyToRelease();

        $this->get(route('releases.start', $project))
            ->assertOk()
            ->assertSee(__('releases.heading', ['project' => $project->name]))
            ->assertSee(__('releases.preconditions_heading'))
            ->assertSee($project->workflowTemplate->name)
            ->assertSee(__('releases.precondition_roles_ok'));
    }

    public function test_an_administrator_starts_a_release_and_sees_the_frozen_chain(): void
    {
        $project = $this->projectReadyToRelease();

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::where('project_id', $project->id)->firstOrFail();

        $this->assertSame('v2.4.0', $release->label);

        $steps = $release->steps()->with('assignedUser')->get();

        $component->assertSee(__('releases.started_heading', ['label' => 'v2.4.0']));
        $component->assertSee(__('releases.chain_heading'));

        foreach ($steps as $step) {
            $component->assertSee($step->name);
            $component->assertSee($step->role_name);
            $component->assertSee($step->assignedUser->name);
        }

        // Stati resi con la parola, non dal solo colore.
        $component->assertSee(ReleaseStepStatus::Active->label());
        $component->assertSee(ReleaseStepStatus::Blocked->label());
    }

    public function test_the_confirmation_leads_to_the_detail_of_the_release_just_started(): void
    {
        /*
         * La conferma di avvio e l'unico punto in cui si sa quale release e appena
         * nata: senza questa uscita chi ha avviato dovrebbe ritrovarla a mano. Il
         * collegamento porta alla release **appena avviata**, non a un elenco — ed e
         * cio che l'asserzione fissa, insieme alla sua presenza.
         */
        $project = $this->projectReadyToRelease();

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::where('project_id', $project->id)->firstOrFail();

        $component->assertSee(route('releases.show', $release));
        $component->assertSee(__('releases.started_open_detail'));
    }

    public function test_a_missing_label_is_refused_in_validation_without_creating_anything(): void
    {
        $project = $this->projectReadyToRelease();

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', '')
            ->call('start')
            ->assertHasErrors(['label' => 'required']);

        $this->assertSame(0, Release::count());
        $this->assertSame(0, ReleaseStep::count());
    }

    public function test_a_duplicate_label_is_refused_in_validation_without_creating_anything(): void
    {
        $project = $this->projectReadyToRelease();

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors();

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasErrors(['label' => 'unique']);

        $this->assertSame(1, Release::count());
    }

    public function test_a_project_without_a_responsible_shows_the_reason_and_asks_for_an_override(): void
    {
        /*
         * Il motivo si mostra ancora, ma non e piu un vicolo cieco: da US-013 lo
         * stesso modulo permette di indicare chi ne risponde per questa release, e
         * il rimando alla mappatura del progetto resta per chi vuole cambiare il
         * default invece di gestire un'eccezione.
         */
        $project = $this->projectReadyToRelease();

        $orphan = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->where('role_id', $orphan->id)
            ->delete();

        $component = Livewire::test('releases.start', ['project' => $project->fresh()]);

        $component->assertSee(__('releases.blocked_heading'));
        $component->assertSee($orphan->name);
        $component->assertSee(__('releases.blocked_hint_assignments'));
        $component->assertSee(__('releases.override_heading'));
        $component->assertSee(__('releases.override_required'));
    }

    public function test_every_process_role_has_a_select_with_the_project_responsible_preselected(): void
    {
        $project = $this->projectReadyToRelease();

        $component = Livewire::test('releases.start', ['project' => $project]);

        $expected = $project->assignments->pluck('user_id', 'role_id');
        $selected = $component->get('overrides');

        foreach ($project->workflowTemplate->stepDefinitions->pluck('role')->unique('id') as $role) {
            $this->assertArrayHasKey($role->id, $selected);
            $this->assertSame($expected[$role->id], $selected[$role->id]);

            $component->assertSee(__('releases.override_role_label', ['role' => $role->name]));
        }
    }

    public function test_the_selectable_pool_holds_every_active_member_and_the_inactive_ones_assigned_here(): void
    {
        $project = $this->projectReadyToRelease();

        $outsider = User::factory()->create(['name' => 'Membro Attivo Esterno']);
        $inactiveOutsider = User::factory()->create(['name' => 'Estraneo Disattivato', 'is_active' => false]);

        $assigned = User::query()->whereKey($project->assignments->first()->user_id)->first();
        $assigned->update(['is_active' => false]);

        /*
         * L'insieme si verifica su cio che la pagina rende e non sul computed: e
         * l'elenco che l'utente vede a dover essere giusto, e un'asserzione sullo
         * stato interno resterebbe verde anche se la vista ne mostrasse un altro.
         */
        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->assertSee($outsider->name)
            // L'assegnato corrente resta in elenco anche disattivato, marcato come
            // tale: altrimenti sparirebbe proprio mentre lo si sta sostituendo.
            ->assertSee(__('releases.override_inactive_person', ['name' => $assigned->name]))
            ->assertDontSee($inactiveOutsider->name);
    }

    public function test_an_override_on_an_uncovered_role_clears_the_banner_and_enables_the_submit(): void
    {
        $project = $this->projectReadyToRelease();

        $orphan = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->where('role_id', $orphan->id)
            ->delete();

        $substitute = User::factory()->create();

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->assertSee(__('releases.blocked_heading'))
            ->set('overrides.'.$orphan->id, $substitute->id)
            // Il riquadro torna a essere quello delle precondizioni: un ruolo
            // scoperto con un override valido non e piu un blocco.
            ->assertDontSee(__('releases.blocked_heading'))
            ->assertSee(__('releases.preconditions_heading'))
            ->assertSee(__('releases.precondition_roles_ok'));
    }

    public function test_an_override_over_an_inactive_project_responsible_clears_the_banner(): void
    {
        $project = $this->projectReadyToRelease();

        $assignment = $project->assignments->first();
        $responsible = User::query()->whereKey($assignment->user_id)->first();
        $responsible->update(['is_active' => false]);

        $substitute = User::factory()->create();

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->assertSee(__('releases.blocked_heading'))
            ->set('overrides.'.$assignment->role_id, $substitute->id)
            ->assertDontSee(__('releases.blocked_heading'))
            ->assertSee(__('releases.precondition_roles_ok'));
    }

    public function test_the_submit_is_refused_while_a_blocking_role_has_no_override(): void
    {
        $project = $this->projectReadyToRelease();

        $orphan = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->where('role_id', $orphan->id)
            ->delete();

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->set('label', 'v2.4.0')
            ->set('overrides.'.$orphan->id, '')
            ->call('start')
            ->assertHasErrors(['overrides.'.$orphan->id => 'required'])
            // Il messaggio e quello deciso e non quello generico di Laravel: dice
            // cosa fare, non che un campo e obbligatorio.
            ->assertSee(__('releases.override_required'));

        $this->assertSame(0, Release::count());
    }

    public function test_the_start_form_does_not_query_per_role(): void
    {
        /*
         * Il modulo rende un select per ruolo e ricalcola la mappatura effettiva a
         * ogni render: e esattamente la forma in cui un N+1 entra senza farsi notare.
         * L'insieme selezionabile e una query sola qualunque sia il numero di ruoli, e
         * la mappatura effettiva si costruisce in memoria.
         */
        $few = Livewire::test('releases.start', ['project' => $this->projectReadyToRelease(steps: 2)]);
        $many = Livewire::test('releases.start', ['project' => $this->projectReadyToRelease(steps: 6)]);

        $fewCost = $this->queriesWhile(fn () => $few->call('$refresh'));
        $manyCost = $this->queriesWhile(fn () => $many->call('$refresh'));

        $this->assertSame(
            $fewCost,
            $manyCost,
            "Il modulo e costato {$fewCost} query su due ruoli e {$manyCost} su sei: manca un eager loading."
        );
    }

    public function test_choosing_an_override_does_not_query_per_role(): void
    {
        // Stessa invariante sul ramo con una sostituzione scelta: la persona indicata
        // si risolve dall'insieme gia in memoria, non con una lettura per ruolo.
        $few = $this->projectReadyToRelease(steps: 2);
        $many = $this->projectReadyToRelease(steps: 6);

        $substitute = User::factory()->create();

        $fewComponent = Livewire::test('releases.start', ['project' => $few]);
        $manyComponent = Livewire::test('releases.start', ['project' => $many]);

        $fewRole = $few->workflowTemplate->stepDefinitions->first()->role_id;
        $manyRole = $many->workflowTemplate->stepDefinitions->first()->role_id;

        $fewCost = $this->queriesWhile(fn () => $fewComponent->set('overrides.'.$fewRole, $substitute->id));
        $manyCost = $this->queriesWhile(fn () => $manyComponent->set('overrides.'.$manyRole, $substitute->id));

        $this->assertSame(
            $fewCost,
            $manyCost,
            "La selezione e costata {$fewCost} query su due ruoli e {$manyCost} su sei: la risoluzione avviene per ruolo."
        );
    }

    public function test_a_start_with_an_override_freezes_the_step_with_the_chosen_member(): void
    {
        $project = $this->projectReadyToRelease();

        $role = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        $substitute = User::factory()->create();

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set('overrides.'.$role->id, $substitute->id)
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::where('project_id', $project->id)->firstOrFail();

        foreach ($release->steps()->where('role_id', $role->id)->get() as $step) {
            $this->assertSame($substitute->id, $step->assigned_user_id);
        }

        // La conferma mostra chi e stato congelato: e l'unica prova visiva che la
        // sostituzione ha avuto effetto.
        $component->assertSee($substitute->name);
    }

    public function test_the_project_mapping_is_untouched_by_a_start_with_an_override(): void
    {
        $project = $this->projectReadyToRelease();

        $role = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        $before = ProjectRoleAssignment::query()
            ->orderBy('id')
            ->get(['id', 'project_id', 'role_id', 'user_id'])
            ->toArray();

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set('overrides.'.$role->id, User::factory()->create()->id)
            ->call('start')
            ->assertHasNoErrors();

        $this->assertSame(
            $before,
            ProjectRoleAssignment::query()->orderBy('id')->get(['id', 'project_id', 'role_id', 'user_id'])->toArray(),
            "L'override vale per la singola release: la mappatura del progetto non cambia."
        );
    }

    public function test_an_override_towards_an_inactive_member_is_a_message_and_not_an_unhandled_error(): void
    {
        $project = $this->projectReadyToRelease();

        // La persona e in elenco perche assegnata su questo progetto, e viene
        // disattivata dopo che la schermata ha letto le precondizioni: e la finestra
        // fra controllo e scrittura, e va chiusa con un messaggio.
        $assignment = $project->assignments->first();
        $other = $project->assignments->last();
        $inactive = User::query()->whereKey($assignment->user_id)->first();

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('overrides.'.$other->role_id, $inactive->id);

        $inactive->update(['is_active' => false]);

        $component->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors()
            ->assertSee($inactive->name);

        $this->assertSame(0, Release::count());
    }

    public function test_a_non_textual_override_is_not_a_choice_and_does_not_break_the_render(): void
    {
        /*
         * `overrides` e legata a `wire:model`, quindi il suo **contenuto** e vincolato
         * dalla validazione ma la sua **forma** no: un valore annidato non e
         * selezionabile dall'interfaccia, ed e comunque inviabile. Convertirlo darebbe
         * un warning che Laravel promuove a eccezione, cioe un 500 al primo render —
         * prima che la validazione possa dire di no.
         *
         * Un valore non testuale non e una scelta: il ruolo ricade sul responsabile
         * di progetto, esattamente come se nessuno avesse toccato il select.
         */
        $project = $this->projectReadyToRelease();

        $role = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        $expected = $project->assignments->firstWhere('role_id', $role->id)->user_id;

        Livewire::test('releases.start', ['project' => $project])
            ->set('overrides.'.$role->id, ['annidato'])
            ->assertSee(__('releases.override_heading'))
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::where('project_id', $project->id)->sole();

        foreach ($release->steps()->where('role_id', $role->id)->get() as $step) {
            $this->assertSame($expected, $step->assigned_user_id);
        }
    }

    public function test_a_non_textual_override_still_leaves_an_uncovered_role_blocking(): void
    {
        // Sull'altro ramo il valore scartato non deve **coprire** nulla: il ruolo
        // resta scoperto, e l'invio viene rifiutato dalla regola che lo esige.
        $project = $this->projectReadyToRelease();

        $orphan = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->where('role_id', $orphan->id)
            ->delete();

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->set('overrides.'.$orphan->id, ['annidato'])
            ->assertSee(__('releases.blocked_heading'))
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasErrors(['overrides.'.$orphan->id => 'required']);

        $this->assertSame(0, Release::count());
    }

    public function test_the_baseline_of_each_select_cannot_be_rewritten_from_the_browser(): void
    {
        /*
         * `primedDefaults` decide quali scelte contano come sostituzione. Se il client
         * potesse riscriverla, un preselezionato mai toccato diventerebbe un override
         * a comando — cioe la protezione contro la mappatura cambiata sotto il modulo
         * si disattiverebbe con un `$set`.
         */
        $project = $this->projectReadyToRelease();

        $role = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('releases.start', ['project' => $project])
            ->set('primedDefaults.'.$role->id, '');
    }

    public function test_the_selects_are_not_offered_when_no_choice_of_person_can_unblock(): void
    {
        $project = $this->projectReadyToRelease();
        $project->workflowTemplate->update(['is_active' => false]);

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->assertSee(__('releases.blocked_heading'))
            ->assertDontSee(__('releases.override_heading'));
    }

    public function test_a_project_without_a_template_explains_why_it_cannot_release(): void
    {
        $project = Project::factory()->create();

        Livewire::test('releases.start', ['project' => $project])
            ->assertSee(__('releases.blocked_heading'))
            ->assertSee(__('releases.blocked_without_template'));
    }

    public function test_an_inactive_project_explains_why_it_cannot_release(): void
    {
        $project = $this->projectReadyToRelease();
        $project->update(['is_active' => false]);

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->assertSee(__('releases.blocked_inactive_project'));
    }

    public function test_an_inactive_responsible_is_named_and_can_be_replaced_from_the_form(): void
    {
        $project = $this->projectReadyToRelease();

        $responsible = User::query()->whereKey($project->assignments->first()->user_id)->first();
        $responsible->update(['is_active' => false]);

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->assertSee(__('releases.blocked_heading'))
            ->assertSee($responsible->name)
            ->assertSee(__('releases.override_heading'));
    }

    public function test_a_start_refused_by_the_action_is_a_message_and_not_an_unhandled_error(): void
    {
        $project = $this->projectReadyToRelease();

        $component = Livewire::test('releases.start', ['project' => $project]);

        // Il progetto viene disattivato **dopo** che il componente ha letto le
        // precondizioni: e la finestra fra controllo e scrittura, e va chiusa con
        // un messaggio, non con un 500.
        Project::query()->whereKey($project->id)->update(['is_active' => false]);

        $component->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors()
            ->assertSee(__('releases.blocked_inactive_project'));

        $this->assertSame(0, Release::count());
    }

    public function test_a_refusal_arriving_after_the_summary_names_the_role_that_blocked_it(): void
    {
        $project = $this->projectReadyToRelease();

        $orphan = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        $component = Livewire::test('releases.start', ['project' => $project]);

        // Il responsabile sparisce **dopo** che la schermata ha letto le
        // precondizioni: e la finestra fra controllo e scrittura. Il messaggio
        // nasce dall'eccezione, che porta con se il ruolo scoperto, e non da un
        // ricalcolo dello stato corrente — cosi dice cosa ha bloccato quel
        // tentativo anche se nel frattempo la causa fosse stata risolta.
        $project->assignments->firstWhere('role_id', $orphan->id)->delete();

        $component->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors()
            ->assertSee($orphan->name);

        $this->assertSame(0, Release::count());
    }

    public function test_the_start_command_appears_on_the_project_list_for_administrators(): void
    {
        $ready = $this->projectReadyToRelease();

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee(__('releases.start_from_project'))
            ->assertSee(route('releases.start', $ready), escape: false);
    }

    public function test_the_start_command_is_disabled_with_a_reason_when_no_override_can_unblock(): void
    {
        // Processo assente: nessuna scelta di persona lo risolve, quindi il comando
        // resta visibile ma disabilitato con il motivo accanto.
        $blocked = Project::factory()->create();

        $response = $this->get(route('projects.index'))->assertOk();

        $response->assertDontSee(route('releases.start', $blocked), escape: false);
        $response->assertSee(__('releases.blocked_without_template'));
    }

    public function test_the_start_command_is_disabled_for_an_inactive_project_and_an_unusable_process(): void
    {
        $inactive = $this->projectReadyToRelease();
        $inactive->update(['is_active' => false]);

        $unusable = $this->projectReadyToRelease();
        $unusable->workflowTemplate->update(['is_active' => false]);

        $response = $this->get(route('projects.index'))->assertOk();

        $response->assertDontSee(route('releases.start', $inactive), escape: false);
        $response->assertDontSee(route('releases.start', $unusable), escape: false);
        $response->assertSee(__('releases.blocked_inactive_project'));
    }

    public function test_the_start_command_stays_reachable_when_an_override_can_unblock(): void
    {
        /*
         * Ruolo scoperto e responsabile disattivato non chiudono piu la porta: da
         * US-013 si risolvono nella schermata di avvio, indicando chi ne risponde
         * per quella release. Disabilitare il comando qui costringerebbe a cambiare
         * il default del progetto per gestire un'assenza di un giorno.
         */
        $uncovered = $this->projectReadyToRelease();
        ProjectRoleAssignment::where('project_id', $uncovered->id)->limit(1)->delete();

        $withInactive = $this->projectReadyToRelease();
        User::query()->whereKey($withInactive->assignments->first()->user_id)->update(['is_active' => false]);

        $response = $this->get(route('projects.index'))->assertOk();

        $response->assertSee(route('releases.start', $uncovered), escape: false);
        $response->assertSee(route('releases.start', $withInactive), escape: false);
        $response->assertSee(__('releases.start_needs_override'));
    }

    public function test_the_project_list_does_not_query_per_row_for_the_start_command(): void
    {
        Project::factory()->count(3)->create();
        $this->projectReadyToRelease();
        $this->projectReadyToRelease();

        $before = $this->queriesWhile(fn () => $this->get(route('projects.index'))->assertOk());

        Project::factory()->count(3)->create();
        $this->projectReadyToRelease();
        $this->projectReadyToRelease();

        $after = $this->queriesWhile(fn () => $this->get(route('projects.index'))->assertOk());

        $this->assertSame(
            $before,
            $after,
            "L'elenco e costato {$before} query con cinque progetti e {$after} con dieci: manca un eager loading."
        );
    }

    public function test_the_confirmation_panel_does_not_query_per_step(): void
    {
        // Il pannello mostra responsabile e stato di ogni step: senza eager
        // loading il costo crescerebbe con la lunghezza della catena, ed e il
        // rischio strutturale che il PRD indica per le catene annidate.
        $short = Livewire::test('releases.start', ['project' => $this->projectReadyToRelease(steps: 2)])
            ->set('label', 'v2.4.0')
            ->call('start');

        $long = Livewire::test('releases.start', ['project' => $this->projectReadyToRelease(steps: 6)])
            ->set('label', 'v2.4.0')
            ->call('start');

        $shortCost = $this->queriesWhile(fn () => $short->call('$refresh'));
        $longCost = $this->queriesWhile(fn () => $long->call('$refresh'));

        $this->assertSame(
            $shortCost,
            $longCost,
            "Il pannello e costato {$shortCost} query su due step e {$longCost} su sei: manca un eager loading."
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
     * Progetto pronto a rilasciare: processo utilizzabile con tre step e un
     * responsabile per ogni ruolo previsto.
     */
    private function projectReadyToRelease(int $steps = 3): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $roles = Role::factory()->count($steps)->create();

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
