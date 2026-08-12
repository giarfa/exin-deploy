<?php

namespace Tests\Feature\Configuration;

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_configure_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::factory()->create();

        $this->assertTrue($admin->can('viewAny', Role::class));
        $this->assertTrue($admin->can('create', Role::class));
        $this->assertTrue($admin->can('update', $role));
        $this->assertTrue($admin->can('toggleActivation', $role));
    }

    public function test_a_member_cannot_configure_roles(): void
    {
        $member = User::factory()->member()->create();
        $role = Role::factory()->create();

        $this->assertFalse($member->can('viewAny', Role::class));
        $this->assertFalse($member->can('create', Role::class));
        $this->assertFalse($member->can('update', $role));
        $this->assertFalse($member->can('toggleActivation', $role));
        $this->assertFalse($member->can('delete', $role));
    }

    public function test_a_free_role_can_be_deleted_by_an_administrator(): void
    {
        $this->assertTrue(
            User::factory()->admin()->create()->can('delete', Role::factory()->create())
        );
    }

    public function test_a_referenced_role_cannot_be_deleted_even_by_an_administrator(): void
    {
        // Il vincolo vale anche per gli amministratori: se l'ability passasse dal
        // filtro before() il divieto sarebbe scavalcato senza accorgersene.
        $admin = User::factory()->admin()->create();

        $this->assertFalse(
            $admin->can('delete', ProjectRoleAssignment::factory()->create()->role)
        );
        $this->assertFalse(
            $admin->can('delete', DefaultRoleAssignment::factory()->create()->role)
        );
    }

    public function test_an_administrator_can_configure_projects_and_their_assignments(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();

        $this->assertTrue($admin->can('viewAny', Project::class));
        $this->assertTrue($admin->can('create', Project::class));
        $this->assertTrue($admin->can('update', $project));
        $this->assertTrue($admin->can('toggleActivation', $project));
        $this->assertTrue($admin->can('manageAssignments', $project));
    }

    public function test_a_member_cannot_configure_projects_or_their_assignments(): void
    {
        $member = User::factory()->member()->create();
        $project = Project::factory()->create();

        $this->assertFalse($member->can('viewAny', Project::class));
        $this->assertFalse($member->can('create', Project::class));
        $this->assertFalse($member->can('update', $project));
        $this->assertFalse($member->can('manageAssignments', $project));
    }

    public function test_nobody_can_delete_a_project(): void
    {
        // Un progetto contiene lo storico dei suoi rilasci: si disattiva.
        $project = Project::factory()->create();

        $this->assertFalse(User::factory()->admin()->create()->can('delete', $project));
        $this->assertFalse(User::factory()->member()->create()->can('delete', $project));
    }

    public function test_only_an_administrator_manages_the_default_mapping(): void
    {
        $assignment = DefaultRoleAssignment::factory()->create();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->assertTrue($admin->can('viewAny', DefaultRoleAssignment::class));
        $this->assertTrue($admin->can('create', DefaultRoleAssignment::class));
        $this->assertTrue($admin->can('update', $assignment));
        $this->assertTrue($admin->can('delete', $assignment));

        $this->assertFalse($member->can('viewAny', DefaultRoleAssignment::class));
        $this->assertFalse($member->can('create', DefaultRoleAssignment::class));
        $this->assertFalse($member->can('update', $assignment));
        $this->assertFalse($member->can('delete', $assignment));
    }

    /**
     * L'autorizzazione e applicata due volte e non e ridondanza: il middleware
     * blocca la rotta, le Gate dentro i componenti bloccano le singole azioni
     * Livewire, che non ripassano da qui.
     */
    public function test_a_member_is_forbidden_from_every_configuration_route(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->member()->create());

        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('projects.index'))->assertForbidden();
        $this->get(route('projects.assignments', $project))->assertForbidden();
        $this->get(route('default-assignments.index'))->assertForbidden();
    }

    public function test_an_administrator_reaches_every_configuration_route(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('roles.index'))->assertOk();
        $this->get(route('projects.index'))->assertOk();
        $this->get(route('projects.assignments', $project))->assertOk();
        $this->get(route('default-assignments.index'))->assertOk();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $project = Project::factory()->create();

        $this->get(route('roles.index'))->assertRedirect(route('login'));
        $this->get(route('projects.index'))->assertRedirect(route('login'));
        $this->get(route('projects.assignments', $project))->assertRedirect(route('login'));
        $this->get(route('default-assignments.index'))->assertRedirect(route('login'));
    }
}
