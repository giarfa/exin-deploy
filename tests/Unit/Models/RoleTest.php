<?php

namespace Tests\Unit\Models;

use App\Models\DefaultRoleAssignment;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\StepDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_receives_a_uuid_primary_key(): void
    {
        $role = Role::factory()->create();

        $this->assertTrue(Str::isUuid($role->id), "L'id [{$role->id}] non e un UUID valido.");
    }

    public function test_it_is_active_by_default(): void
    {
        $role = Role::factory()->create();

        // `assertSame` e non `assertTrue`: verifica anche che il cast abbia
        // prodotto un booleano e non l'intero letto dalla colonna.
        $this->assertSame(true, $role->is_active);
    }

    public function test_the_inactive_state_produces_a_deactivated_role(): void
    {
        $this->assertFalse(Role::factory()->inactive()->create()->is_active);
    }

    public function test_the_active_scope_excludes_deactivated_roles(): void
    {
        $active = Role::factory()->create();
        $inactive = Role::factory()->inactive()->create();

        $found = Role::active()->pluck('id');

        $this->assertTrue($found->contains($active->id));
        $this->assertFalse($found->contains($inactive->id));
    }

    public function test_a_free_role_is_not_referenced(): void
    {
        $this->assertFalse(Role::factory()->create()->isReferenced());
    }

    public function test_a_role_used_by_the_default_mapping_is_referenced(): void
    {
        $role = Role::factory()->create();
        DefaultRoleAssignment::factory()->for($role)->create();

        $this->assertTrue($role->isReferenced());
        $this->assertSame(1, $role->referenceCounts()['defaultAssignment']);
    }

    public function test_a_role_used_by_a_project_mapping_is_referenced(): void
    {
        $role = Role::factory()->create();
        ProjectRoleAssignment::factory()->count(2)->for($role)->create();

        $this->assertTrue($role->isReferenced());
        $this->assertSame(2, $role->referenceCounts()['projectAssignments']);
    }

    public function test_a_role_responsible_for_a_template_step_is_referenced(): void
    {
        $role = Role::factory()->create();
        StepDefinition::factory()->count(2)->for($role)->create();

        $this->assertTrue($role->isReferenced());
        $this->assertSame(2, $role->referenceCounts()['stepDefinitions']);
    }

    public function test_the_usage_label_names_the_use_inside_templates(): void
    {
        $role = Role::factory()->create();
        StepDefinition::factory()->for($role)->create();

        $this->assertStringContainsString(
            trans_choice('roles.used_templates', 1, ['count' => 1]),
            $role->usageLabel()
        );
    }

    public function test_a_free_role_can_be_deleted(): void
    {
        $role = Role::factory()->create();

        $role->delete();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }
}
