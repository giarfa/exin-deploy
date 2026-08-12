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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Flusso di avvio dall'interfaccia: cosa vede chi avvia prima del tentativo,
 * cosa ottiene dopo, e come vengono resi i rifiuti.
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

    public function test_a_project_without_a_responsible_shows_the_reason_and_disables_the_command(): void
    {
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

    public function test_an_inactive_responsible_blocks_the_start_and_is_named(): void
    {
        $project = $this->projectReadyToRelease();

        $responsible = User::query()->whereKey($project->assignments->first()->user_id)->first();
        $responsible->update(['is_active' => false]);

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->assertSee(__('releases.blocked_heading'))
            ->assertSee($responsible->name);
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

    public function test_the_start_command_is_disabled_with_a_reason_when_the_project_is_not_ready(): void
    {
        $blocked = Project::factory()->create();

        $response = $this->get(route('projects.index'))->assertOk();

        // Nessun collegamento alla pagina di avvio per un progetto non avviabile:
        // il comando resta visibile ma disabilitato, con il motivo accanto.
        $response->assertDontSee(route('releases.start', $blocked), escape: false);
        $response->assertSee(__('releases.blocked_without_template'));
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
