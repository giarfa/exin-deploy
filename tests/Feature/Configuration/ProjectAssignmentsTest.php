<?php

namespace Tests\Feature\Configuration;

use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_page_lists_every_active_role(): void
    {
        $project = Project::factory()->create();
        $roles = Role::factory()->count(3)->create();

        $response = $this->get(route('projects.assignments', $project))->assertOk();

        foreach ($roles as $role) {
            $response->assertSee($role->name);
        }
    }

    public function test_a_role_created_after_the_project_appears_as_unassigned(): void
    {
        $project = Project::factory()->create();
        $role = Role::factory()->create(['name' => 'Ruolo Tardivo']);

        Livewire::test('projects.assignments', ['project' => $project])
            ->assertSee($role->name)
            ->assertSet("selections.{$role->id}", '');
    }

    public function test_an_administrator_assigns_a_person_to_a_role(): void
    {
        $project = Project::factory()->create();
        $role = Role::factory()->create();
        $person = User::factory()->member()->create();

        Livewire::test('projects.assignments', ['project' => $project])
            ->set("selections.{$role->id}", $person->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('project_role_assignments', [
            'project_id' => $project->id,
            'role_id' => $role->id,
            'user_id' => $person->id,
        ]);
    }

    public function test_replacing_a_person_keeps_a_single_row_for_the_pair(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();
        $replacement = User::factory()->member()->create();

        Livewire::test('projects.assignments', ['project' => $assignment->project])
            ->set("selections.{$assignment->role_id}", $replacement->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, ProjectRoleAssignment::where([
            'project_id' => $assignment->project_id,
            'role_id' => $assignment->role_id,
        ])->count());

        $this->assertSame($replacement->id, $assignment->fresh()->user_id);
    }

    public function test_clearing_the_selection_removes_the_assignment(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();

        Livewire::test('projects.assignments', ['project' => $assignment->project])
            ->set("selections.{$assignment->role_id}", '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('project_role_assignments', ['id' => $assignment->id]);
    }

    public function test_assigning_a_deactivated_person_is_rejected_with_an_explicit_message(): void
    {
        $project = Project::factory()->create();
        $role = Role::factory()->create();
        $inactive = User::factory()->member()->inactive()->create(['name' => 'Paolo Venturi']);

        $component = Livewire::test('projects.assignments', ['project' => $project])
            ->set("selections.{$role->id}", $inactive->id)
            ->call('save')
            ->assertHasErrors("selections.{$role->id}");

        $this->assertStringContainsString(
            'Paolo Venturi',
            $component->errors()->first("selections.{$role->id}"),
        );

        $this->assertDatabaseCount('project_role_assignments', 0);
    }

    public function test_a_deactivated_role_with_an_assignment_stays_visible(): void
    {
        // Nasconderlo renderebbe invisibile una responsabilita che esiste.
        $assignment = ProjectRoleAssignment::factory()->create();
        $assignment->role->update(['is_active' => false]);

        Livewire::test('projects.assignments', ['project' => $assignment->project])
            ->assertSee($assignment->role->name)
            ->assertSee(__('projects.inactive_role_note'));
    }

    public function test_a_deactivated_role_without_assignment_is_not_listed(): void
    {
        $project = Project::factory()->create();
        $role = Role::factory()->inactive()->create(['name' => 'Ruolo Ritirato']);

        Livewire::test('projects.assignments', ['project' => $project])
            ->assertDontSee($role->name);
    }

    public function test_an_assigned_person_deactivated_later_is_flagged(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();
        $assignment->user->update(['is_active' => false]);

        Livewire::test('projects.assignments', ['project' => $assignment->project])
            ->assertSee(__('projects.inactive_person_note'));

        // L'assegnazione resta: rimuoverla d'ufficio cancellerebbe una traccia.
        $this->assertDatabaseHas('project_role_assignments', ['id' => $assignment->id]);
    }

    public function test_a_role_outside_the_listing_cannot_be_assigned(): void
    {
        $project = Project::factory()->create();
        $hidden = Role::factory()->inactive()->create();
        $person = User::factory()->member()->create();

        Livewire::test('projects.assignments', ['project' => $project])
            ->set("selections.{$hidden->id}", $person->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('project_role_assignments', ['role_id' => $hidden->id]);
    }

    public function test_a_member_cannot_reach_or_invoke_the_screen(): void
    {
        $project = Project::factory()->create();
        $role = Role::factory()->create();
        $person = User::factory()->member()->create();

        $this->actingAs(User::factory()->member()->create());

        $this->get(route('projects.assignments', $project))->assertForbidden();

        Livewire::test('projects.assignments', ['project' => $project])
            ->set("selections.{$role->id}", $person->id)
            ->call('save')
            ->assertForbidden();
    }

    public function test_the_screen_does_not_query_per_row(): void
    {
        $project = Project::factory()->create();
        Role::factory()->count(5)->create()->each(
            fn (Role $role) => ProjectRoleAssignment::factory()->for($project)->for($role)->create()
        );

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::test('projects.assignments', ['project' => $project])->assertOk();

        $this->assertLessThan(8, $queries, "Eseguite {$queries} query: sospetto N+1 sulla pagina dei responsabili.");
    }
}
