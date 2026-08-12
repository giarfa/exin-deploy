<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_receives_a_uuid_primary_key(): void
    {
        $project = Project::factory()->create();

        $this->assertTrue(Str::isUuid($project->id), "L'id [{$project->id}] non e un UUID valido.");
    }

    public function test_it_is_active_by_default(): void
    {
        $project = Project::factory()->create();

        // `assertSame` e non `assertTrue`: verifica anche che il cast abbia
        // prodotto un booleano e non l'intero letto dalla colonna.
        $this->assertSame(true, $project->is_active);
    }

    public function test_the_inactive_state_produces_a_deactivated_project(): void
    {
        $this->assertFalse(Project::factory()->inactive()->create()->is_active);
    }

    public function test_the_active_scope_excludes_deactivated_projects(): void
    {
        $active = Project::factory()->create();
        $inactive = Project::factory()->inactive()->create();

        $found = Project::active()->pluck('id');

        $this->assertTrue($found->contains($active->id));
        $this->assertFalse($found->contains($inactive->id));
    }

    public function test_the_route_key_stays_the_identifier_and_not_the_slug(): void
    {
        // Lo slug e modificabile: usarlo come chiave di rotta romperebbe i
        // collegamenti gia diffusi alla prima rinomina.
        $this->assertSame('id', Project::factory()->create()->getRouteKeyName());
    }

    public function test_it_exposes_its_role_assignments(): void
    {
        $project = Project::factory()->create();
        ProjectRoleAssignment::factory()->count(3)->for($project)->create();

        $this->assertCount(3, $project->assignments);
    }
}
