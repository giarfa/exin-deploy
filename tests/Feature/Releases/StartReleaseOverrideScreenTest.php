<?php

namespace Tests\Feature\Releases;

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
 * Il modulo di avvio con la scelta dei responsabili (US-013, FR-029): cosa
 * propone, cosa rifiuta, e cosa lascia intatto.
 *
 * Bar `NORMAL`: HTML reso e interazioni Livewire, nessun browser e nessuna
 * matrice di viewport.
 */
class StartReleaseOverrideScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_form_lists_each_role_once_even_when_two_steps_share_it(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared, $other] = $this->rolesOf($project);

        $component = Livewire::test('releases.start', ['project' => $project]);

        // Tre step, due ruoli: i selettori sono due, e sono indicizzati per ruolo.
        $this->assertSame(
            [$shared->id, $other->id],
            array_keys($component->get('responsibles'))
        );

        $component->assertSee($shared->name);
        $component->assertSee($other->name);
    }

    public function test_every_selector_is_prefilled_with_the_responsible_from_the_project_mapping(): void
    {
        $project = $this->projectReadyToRelease();

        $component = Livewire::test('releases.start', ['project' => $project]);

        foreach ($project->assignments as $assignment) {
            $this->assertSame(
                $assignment->user_id,
                $component->get('responsibles')[$assignment->role_id]
            );
        }
    }

    public function test_an_inactive_member_is_not_offered_as_a_choice(): void
    {
        $project = $this->projectReadyToRelease();
        $absent = User::factory()->create(['is_active' => false]);

        Livewire::test('releases.start', ['project' => $project])
            ->assertDontSee($absent->name);
    }

    public function test_a_role_without_a_usable_default_is_empty_and_says_so(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);

        ProjectRoleAssignment::query()
            ->where('project_id', $project->id)
            ->where('role_id', $shared->id)
            ->delete();

        $component = Livewire::test('releases.start', ['project' => $project->fresh()]);

        $this->assertSame('', $component->get('responsibles')[$shared->id]);
        $component->assertSee(__('releases.responsible_choice_required'));
        $component->assertSee(__('releases.responsible_choose_option'));
    }

    public function test_starting_without_touching_the_selectors_reproduces_the_previous_behaviour(): void
    {
        $project = $this->projectReadyToRelease();

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::query()->where('project_id', $project->id)->sole();
        $byRole = $project->assignments->keyBy('role_id');

        foreach ($release->steps as $step) {
            $this->assertSame($byRole[$step->role_id]->user_id, $step->assigned_user_id);
            $this->assertFalse($step->responsible_overridden);
        }
    }

    public function test_overriding_one_role_moves_every_step_of_that_role(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared, $other] = $this->rolesOf($project);
        $stand = User::factory()->create();

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $stand->id)
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::query()->where('project_id', $project->id)->sole();

        $moved = $release->steps()->where('role_id', $shared->id)->get();

        $this->assertCount(2, $moved);

        foreach ($moved as $step) {
            $this->assertSame($stand->id, $step->assigned_user_id);
            $this->assertTrue($step->responsible_overridden);
        }

        $untouched = $release->steps()->where('role_id', $other->id)->sole();

        $this->assertSame(
            $project->assignments->firstWhere('role_id', $other->id)->user_id,
            $untouched->assigned_user_id
        );
        $this->assertFalse($untouched->responsible_overridden);
    }

    public function test_an_uncovered_role_warns_without_disabling_the_command_and_can_be_covered_here(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);

        ProjectRoleAssignment::query()
            ->where('project_id', $project->id)
            ->where('role_id', $shared->id)
            ->delete();

        $stand = User::factory()->create();

        $component = Livewire::test('releases.start', ['project' => $project->fresh()]);

        // Avviso, non blocco: la schermata non mostra il riquadro dei motivi che
        // impediscono l'avvio, e il comando resta attivo.
        $component->assertDontSee(__('releases.blocked_heading'));
        $component->assertSee($shared->name);

        $component->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $stand->id)
            ->call('start')
            ->assertHasNoErrors();

        $this->assertSame(1, Release::count());

        foreach (ReleaseStep::query()->where('role_id', $shared->id)->get() as $step) {
            $this->assertSame($stand->id, $step->assigned_user_id);
            $this->assertTrue($step->responsible_overridden);
        }
    }

    public function test_a_role_left_empty_is_refused_on_its_own_field_and_named(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);

        ProjectRoleAssignment::query()
            ->where('project_id', $project->id)
            ->where('role_id', $shared->id)
            ->delete();

        $component = Livewire::test('releases.start', ['project' => $project->fresh()])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasErrors(["responsibles.{$shared->id}" => 'required']);

        // Il messaggio nomina il **ruolo** e non "il campo responsibles.<uuid>":
        // e cio che chi legge deve poter cercare a schermo.
        $this->assertStringContainsString(
            $shared->name,
            json_encode($component->errors()->all(), JSON_THROW_ON_ERROR)
        );

        $this->assertSame(0, Release::count());
        $this->assertSame(0, ReleaseStep::count());
    }

    public function test_a_manipulated_payload_naming_an_inactive_member_is_refused(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);
        $absent = User::factory()->create(['is_active' => false]);

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $absent->id)
            ->call('start')
            ->assertHasErrors("responsibles.{$shared->id}");

        $this->assertSame(0, Release::count());
    }

    public function test_a_manipulated_payload_naming_a_member_that_does_not_exist_is_refused(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", '01a06bca-0000-7000-8000-000000000000')
            ->call('start')
            ->assertHasErrors("responsibles.{$shared->id}");

        $this->assertSame(0, Release::count());
    }

    /**
     * Un ruolo che il processo non prevede non ha un campo nel modulo: la chiave
     * viene **scartata** prima della validazione, e l'avvio prosegue coi
     * predefiniti. Non arriva nemmeno all'Action, che pure lo rifiuterebbe — la
     * copertura di quel rifiuto sta in `StartReleaseOverridesTest`, dove vive il
     * percorso non passante dall'interfaccia.
     */
    public function test_a_role_the_process_does_not_use_is_dropped_before_validation(): void
    {
        $project = $this->projectReadyToRelease();
        $foreign = Role::factory()->create();
        $stand = User::factory()->create();

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$foreign->id}", $stand->id)
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::query()->where('project_id', $project->id)->sole();

        $this->assertSame(0, $release->steps()->where('assigned_user_id', $stand->id)->count());

        foreach ($release->steps as $step) {
            $this->assertFalse($step->responsible_overridden);
        }
    }

    public function test_after_a_refusal_the_preconditions_stay_coherent_and_the_choices_are_kept(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared, $other] = $this->rolesOf($project);
        $stand = User::factory()->create();

        $component = Livewire::test('releases.start', ['project' => $project]);

        // Il progetto viene disattivato **dopo** che la schermata ha letto le
        // precondizioni: e la finestra fra controllo e scrittura.
        Project::query()->whereKey($project->id)->update(['is_active' => false]);

        $component->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $stand->id)
            ->call('start')
            ->assertSee(__('releases.blocked_inactive_project'));

        // Il riepilogo si riallinea: continuare a dire che tutto e a posto sotto un
        // messaggio che dice il contrario sarebbe peggio del rifiuto.
        $component->assertSee(__('releases.blocked_heading'));

        // Le scelte gia fatte restano: perderle dopo un errore su un solo campo
        // obbligherebbe a ricompilare tutto il modulo.
        $this->assertSame($stand->id, $component->get('responsibles')[$shared->id]);
        $this->assertSame(
            $project->assignments->firstWhere('role_id', $other->id)->user_id,
            $component->get('responsibles')[$other->id]
        );

        $this->assertSame(0, Release::count());
    }

    public function test_a_double_submission_shows_the_duplicate_label_and_leaves_nothing_behind(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);
        $stand = User::factory()->create();

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $stand->id)
            ->call('start')
            ->assertHasNoErrors();

        Livewire::test('releases.start', ['project' => $project->fresh()])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $stand->id)
            ->call('start')
            ->assertHasErrors('label');

        $this->assertSame(1, Release::count());
    }

    /**
     * Nessun permesso nuovo: gli override sono ammessi a chi e gia autorizzato ad
     * avviare (`ReleasePolicy::create`). L'asserzione e la stessa gia in vigore per
     * l'avvio — rifiuto e nessuna scrittura — e non introduce un percorso di
     * tracciamento che in avvio non esiste: il registro si aggancia a una release,
     * e qui la release non e ancora nata.
     */
    public function test_a_member_cannot_start_a_release_by_sending_overrides(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);
        $stand = User::factory()->create();

        $this->actingAs(User::factory()->member()->create());

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $stand->id)
            ->call('start')
            ->assertForbidden();

        $this->assertSame(0, Release::count());
    }

    public function test_starting_another_release_resets_the_selectors_to_fresh_defaults(): void
    {
        $project = $this->projectReadyToRelease();
        [$shared] = $this->rolesOf($project);
        $stand = User::factory()->create();

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->set("responsibles.{$shared->id}", $stand->id)
            ->call('start')
            ->assertHasNoErrors();

        $component->call('startAnother');

        $this->assertSame(
            $project->assignments->firstWhere('role_id', $shared->id)->user_id,
            $component->get('responsibles')[$shared->id],
            'Un avvio non deve lasciare in giro le scelte del precedente.'
        );
    }

    public function test_the_form_does_not_query_per_role_nor_per_member(): void
    {
        $small = $this->projectWithDistinctRoles(2);
        $large = $this->projectWithDistinctRoles(8);

        // Molti membri attivi in piu: la lista delle persone e una sola per tutti i
        // selettori, quindi il costo non deve crescere nemmeno con loro.
        User::factory()->count(20)->create();

        $smallCost = $this->queriesWhile(
            fn () => Livewire::test('releases.start', ['project' => $small])->assertOk()
        );

        $largeCost = $this->queriesWhile(
            fn () => Livewire::test('releases.start', ['project' => $large])->assertOk()
        );

        $this->assertSame(
            $smallCost,
            $largeCost,
            "Il modulo e costato {$smallCost} query con due ruoli e {$largeCost} con otto: si interroga per ruolo."
        );
    }

    /**
     * Ruoli del processo, nell'ordine degli step: il primo e usato da due step.
     *
     * @return list<Role>
     */
    private function rolesOf(Project $project): array
    {
        return $project->workflowTemplate->stepDefinitions
            ->unique('role_id')
            ->pluck('role')
            ->values()
            ->all();
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
     * Progetto con un ruolo distinto per step, tutti coperti dalla mappatura.
     */
    private function projectWithDistinctRoles(int $roles): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $project = Project::factory()->withTemplate($template)->create();

        for ($position = 1; $position <= $roles; $position++) {
            $role = Role::factory()->create();

            StepDefinition::factory()->for($template)->create([
                'position' => $position,
                'role_id' => $role->id,
            ]);

            ProjectRoleAssignment::factory()->create([
                'project_id' => $project->id,
                'role_id' => $role->id,
                'user_id' => User::factory()->create()->id,
            ]);
        }

        return $project->fresh();
    }

    /**
     * Progetto pronto a rilasciare: tre step su due ruoli, il primo usato due
     * volte — la forma che rende dimostrabile la granularita per ruolo.
     */
    private function projectReadyToRelease(): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $roles = Role::factory()->count(2)->create();

        foreach ([$roles[0], $roles[1], $roles[0]] as $position => $role) {
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
