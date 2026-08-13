<?php

namespace Tests\Feature\Releases;

use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Il criterio dice "Policy lato server": va verificato che un `member` sia
 * respinto **anche** invocando direttamente l'azione Livewire, che non ripassa
 * dal middleware della rotta.
 */
class ReleasePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_cannot_open_the_start_page(): void
    {
        $project = $this->projectReadyToRelease();

        $this->actingAs(User::factory()->member()->create());

        $this->get(route('releases.start', $project))->assertForbidden();
    }

    public function test_a_member_cannot_invoke_the_start_action_directly(): void
    {
        $project = $this->projectReadyToRelease();

        $this->actingAs(User::factory()->member()->create());

        Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertForbidden();

        $this->assertSame(0, Release::count());
    }

    public function test_a_member_cannot_invoke_the_other_livewire_actions_either(): void
    {
        $project = $this->projectReadyToRelease();

        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start');

        // Chi perde l'autorizzazione mentre la pagina e aperta non deve poter
        // rileggere lo stato del progetto: le azioni Livewire non ripassano dal
        // middleware della rotta, quindi ognuna ha il proprio controllo.
        $this->actingAs(User::factory()->member()->create());

        $component->call('startAnother')->assertForbidden();
    }

    public function test_an_administrator_can_open_the_start_page(): void
    {
        $project = $this->projectReadyToRelease();

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('releases.start', $project))->assertOk();
    }

    public function test_nobody_can_delete_a_release_not_even_an_administrator(): void
    {
        $release = Release::factory()->create();

        // Una release **e** lo storico di un rilascio: cancellarla cancellerebbe
        // la prova di cosa e stato fatto e da chi.
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('delete', $release));
        $this->assertFalse(Gate::forUser(User::factory()->member()->create())->allows('delete', $release));
    }

    public function test_consultation_stays_closed_until_the_specs_that_open_it(): void
    {
        $release = Release::factory()->create();
        $member = User::factory()->member()->create();

        // US-008 aprira il dettaglio ai membri coinvolti, US-009 l'elenco: finche
        // quelle schermate non esistono, l'accesso non e concesso a nessuno oltre
        // agli amministratori.
        $this->assertFalse(Gate::forUser($member)->allows('view', $release));
        $this->assertFalse(Gate::forUser($member)->allows('viewAny', Release::class));

        $administrator = User::factory()->admin()->create();

        $this->assertTrue(Gate::forUser($administrator)->allows('view', $release));
        $this->assertTrue(Gate::forUser($administrator)->allows('viewAny', Release::class));
    }

    public function test_the_start_decision_is_taken_on_the_project(): void
    {
        $project = $this->projectReadyToRelease();

        // La release non esiste ancora quando si decide: l'oggetto della
        // decisione e il progetto su cui si vuole rilasciare.
        $this->assertTrue(
            Gate::forUser(User::factory()->admin()->create())->allows('create', [Release::class, $project])
        );
        $this->assertFalse(
            Gate::forUser(User::factory()->member()->create())->allows('create', [Release::class, $project])
        );
    }

    private function projectReadyToRelease(): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $role = Role::factory()->create();

        $step = StepDefinition::factory()->for($template)->create([
            'position' => 1,
            'role_id' => $role->id,
        ]);

        FieldDefinition::factory()->for($step)->create();

        $project = Project::factory()->withTemplate($template)->create();

        ProjectRoleAssignment::factory()->create([
            'project_id' => $project->id,
            'role_id' => $role->id,
            'user_id' => User::factory()->create()->id,
        ]);

        return $project->fresh();
    }
}
