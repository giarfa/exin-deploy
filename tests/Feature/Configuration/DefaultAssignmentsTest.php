<?php

namespace Tests\Feature\Configuration;

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DefaultAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_page_states_that_the_mapping_is_not_retroactive(): void
    {
        Role::factory()->create();

        $this->get(route('default-assignments.index'))
            ->assertOk()
            ->assertSee(__('assignments.heading'))
            ->assertSee(__('assignments.not_retroactive'));
    }

    public function test_an_administrator_defines_the_default_person_of_a_role(): void
    {
        $role = Role::factory()->create();
        $person = User::factory()->member()->create();

        Livewire::test('default-assignments.index')
            ->set("selections.{$role->id}", $person->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('default_role_assignments', [
            'role_id' => $role->id,
            'user_id' => $person->id,
        ]);
    }

    public function test_replacing_the_default_keeps_a_single_row_per_role(): void
    {
        $assignment = DefaultRoleAssignment::factory()->create();
        $replacement = User::factory()->member()->create();

        Livewire::test('default-assignments.index')
            ->set("selections.{$assignment->role_id}", $replacement->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, DefaultRoleAssignment::where('role_id', $assignment->role_id)->count());
        $this->assertSame($replacement->id, $assignment->fresh()->user_id);
    }

    public function test_clearing_the_selection_removes_the_default(): void
    {
        $assignment = DefaultRoleAssignment::factory()->create();

        Livewire::test('default-assignments.index')
            ->set("selections.{$assignment->role_id}", '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('default_role_assignments', ['id' => $assignment->id]);
    }

    public function test_assigning_a_deactivated_person_is_rejected_with_an_explicit_message(): void
    {
        $role = Role::factory()->create();
        $inactive = User::factory()->member()->inactive()->create(['name' => 'Paolo Venturi']);

        $component = Livewire::test('default-assignments.index')
            ->set("selections.{$role->id}", $inactive->id)
            ->call('save')
            ->assertHasErrors("selections.{$role->id}");

        $this->assertStringContainsString(
            'Paolo Venturi',
            $component->errors()->first("selections.{$role->id}"),
        );

        $this->assertDatabaseCount('default_role_assignments', 0);
    }

    public function test_editing_the_default_does_not_touch_existing_projects(): void
    {
        $project = Project::factory()->create();
        $assignment = ProjectRoleAssignment::factory()->for($project)->create();
        DefaultRoleAssignment::factory()
            ->for($assignment->role)
            ->create(['user_id' => $assignment->user_id]);

        $replacement = User::factory()->member()->create();

        Livewire::test('default-assignments.index')
            ->set("selections.{$assignment->role_id}", $replacement->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($assignment->user_id, $assignment->fresh()->user_id);
    }

    public function test_a_deactivated_role_is_not_listed(): void
    {
        $role = Role::factory()->inactive()->create(['name' => 'Ruolo Ritirato']);

        Livewire::test('default-assignments.index')->assertDontSee($role->name);
    }

    public function test_a_role_outside_the_listing_cannot_receive_a_default(): void
    {
        $hidden = Role::factory()->inactive()->create();
        $person = User::factory()->member()->create();

        Livewire::test('default-assignments.index')
            ->set("selections.{$hidden->id}", $person->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('default_role_assignments', ['role_id' => $hidden->id]);
    }

    public function test_a_member_cannot_invoke_the_screen(): void
    {
        $role = Role::factory()->create();
        $person = User::factory()->member()->create();

        $this->actingAs(User::factory()->member()->create());

        Livewire::test('default-assignments.index')
            ->set("selections.{$role->id}", $person->id)
            ->call('save')
            ->assertForbidden();
    }
}
