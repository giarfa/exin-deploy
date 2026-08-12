<?php

namespace Tests\Unit\Models;

use App\Enums\ReleaseStepStatus;
use App\Models\Concerns\OrderedByPosition;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleaseStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_receives_a_uuid_primary_key(): void
    {
        $step = ReleaseStep::factory()->create();

        $this->assertTrue(Str::isUuid($step->id), "L'id [{$step->id}] non e un UUID valido.");
    }

    public function test_it_freezes_the_role_name_next_to_the_foreign_key(): void
    {
        $step = ReleaseStep::factory()->create();

        $this->assertSame($step->role->name, $step->role_name);
    }

    public function test_renaming_the_role_does_not_rewrite_the_frozen_name(): void
    {
        $step = ReleaseStep::factory()->create();
        $original = $step->role_name;

        $step->role->update(['name' => 'Ruolo rinominato']);

        $this->assertSame($original, $step->fresh()->role_name);
    }

    public function test_it_knows_its_release_and_its_assigned_member(): void
    {
        $step = ReleaseStep::factory()->create();

        $this->assertNotNull($step->release);
        $this->assertNotNull($step->assignedUser);
    }

    public function test_the_position_is_read_back_as_an_integer(): void
    {
        $this->assertSame(1, ReleaseStep::factory()->create()->fresh()->position);
    }

    public function test_a_new_step_is_blocked_and_has_no_conclusion(): void
    {
        $step = ReleaseStep::factory()->create()->fresh();

        $this->assertSame(ReleaseStepStatus::Blocked, $step->status);
        $this->assertNull($step->completed_at);
    }

    public function test_the_factory_offers_the_three_states_of_the_chain(): void
    {
        $this->assertSame(ReleaseStepStatus::Active, ReleaseStep::factory()->active()->create()->fresh()->status);
        $this->assertSame(ReleaseStepStatus::Blocked, ReleaseStep::factory()->blocked()->create()->fresh()->status);

        $completed = ReleaseStep::factory()->completed()->create()->fresh();

        $this->assertSame(ReleaseStepStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_by);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_the_fields_relation_is_ordered_by_position(): void
    {
        $step = ReleaseStep::factory()->create();

        ReleaseStepField::factory()->for($step)->create(['position' => 3, 'label' => 'Terzo']);
        ReleaseStepField::factory()->for($step)->create(['position' => 1, 'label' => 'Primo']);
        ReleaseStepField::factory()->for($step)->create(['position' => 2, 'label' => 'Secondo']);

        $this->assertSame(
            ['Primo', 'Secondo', 'Terzo'],
            $step->fields()->pluck('label')->all()
        );
    }

    public function test_the_snapshot_models_do_not_use_the_reordering_trait(): void
    {
        // Lo snapshot non si riordina: il trait aprirebbe un percorso di scrittura
        // proprio sull'ordine congelato che queste tabelle esistono per proteggere.
        $this->assertNotContains(OrderedByPosition::class, class_uses_recursive(ReleaseStep::class));
        $this->assertNotContains(OrderedByPosition::class, class_uses_recursive(ReleaseStepField::class));
    }

    public function test_a_role_counts_the_release_steps_that_reference_it(): void
    {
        $step = ReleaseStep::factory()->create();

        $role = Role::query()->whereKey($step->role_id)->first();

        $this->assertTrue($role->isReferenced());
        $this->assertSame(1, $role->referenceCounts()['releaseSteps']);
        $this->assertStringContainsString('step di release', $role->usageLabel());
    }

    public function test_the_release_step_count_is_reused_when_already_loaded(): void
    {
        // Il conteggio precaricato non deve produrre una query per riga: e la
        // ragione per cui `releaseSteps` e stato aggiunto ai `withCount()`
        // dell'elenco ruoli.
        ReleaseStep::factory()->create();

        $roles = Role::query()
            ->withCount(['projectAssignments', 'defaultAssignment', 'stepDefinitions', 'releaseSteps'])
            ->get();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $roles->each(fn (Role $role): string => $role->usageLabel());

        $this->assertSame(0, $queries);
    }
}
