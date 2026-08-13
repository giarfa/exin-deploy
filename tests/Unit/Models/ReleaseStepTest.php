<?php

namespace Tests\Unit\Models;

use App\Enums\ReleaseStepStatus;
use App\Models\Concerns\OrderedByPosition;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use App\Models\User;
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
        // Chi chiude uno step e il suo responsabile, non una terza persona.
        $this->assertSame($completed->assigned_user_id, $completed->completed_by);
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

    public function test_the_next_step_is_the_one_that_follows_in_the_same_release(): void
    {
        $release = Release::factory()->create();

        $first = ReleaseStep::factory()->for($release)->create(['position' => 1]);
        $second = ReleaseStep::factory()->for($release)->create(['position' => 2]);
        $third = ReleaseStep::factory()->for($release)->create(['position' => 3]);

        $this->assertSame($second->id, $first->nextStep()->id);
        $this->assertSame($third->id, $second->nextStep()->id);
    }

    public function test_the_last_step_of_the_chain_has_no_next_step(): void
    {
        $release = Release::factory()->create();

        ReleaseStep::factory()->for($release)->create(['position' => 1]);
        $last = ReleaseStep::factory()->for($release)->create(['position' => 2]);

        $this->assertNull($last->nextStep());
    }

    public function test_a_step_of_another_release_is_never_the_next_step(): void
    {
        // Il flusso avanza dentro la propria release: leggere per sola posizione
        // farebbe passare il testimone alla catena di un altro rilascio.
        $step = ReleaseStep::factory()->create(['position' => 1]);

        ReleaseStep::factory()->create(['position' => 2]);

        $this->assertNull($step->nextStep());
    }

    public function test_the_closing_rules_are_indexed_by_field_identifier(): void
    {
        $step = ReleaseStep::factory()->create();

        $required = ReleaseStepField::factory()->for($step)->create(['position' => 1, 'is_required' => true]);
        $optional = ReleaseStepField::factory()->for($step)->optional()->create(['position' => 2]);

        $rules = $step->closingRules();

        $this->assertSame([$required->id, $optional->id], array_keys($rules));
        $this->assertContains('required', $rules[$required->id]);
        $this->assertContains('nullable', $rules[$optional->id]);

        // Le etichette congelate diventano i nomi leggibili dei messaggi: senza,
        // un rifiuto parlerebbe di un UUID.
        $this->assertSame(
            [$required->id => $required->label, $optional->id => $optional->label],
            $step->closingAttributes()
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

    public function test_the_awaiting_user_scope_lists_only_the_open_steps_of_that_person(): void
    {
        $member = User::factory()->create();
        $colleague = User::factory()->create();
        $release = Release::factory()->create();

        $mine = ReleaseStep::factory()->for($release)->active()->create([
            'position' => 1,
            'assigned_user_id' => $member->id,
        ]);

        ReleaseStep::factory()->for($release)->blocked()->create(['position' => 2, 'assigned_user_id' => $member->id]);
        ReleaseStep::factory()->for($release)->completed()->create(['position' => 3, 'assigned_user_id' => $member->id]);
        ReleaseStep::factory()->for($release)->active()->create(['position' => 4, 'assigned_user_id' => $colleague->id]);

        $found = ReleaseStep::query()->awaitingUser($member)->pluck('id');

        $this->assertSame([$mine->id], $found->all());
    }

    public function test_the_awaiting_user_scope_ignores_the_steps_of_a_completed_release(): void
    {
        $member = User::factory()->create();

        $closed = Release::factory()->completed()->create();
        ReleaseStep::factory()->for($closed)->active()->create(['position' => 1, 'assigned_user_id' => $member->id]);

        $this->assertSame([], ReleaseStep::query()->awaitingUser($member)->pluck('id')->all());
    }

    public function test_the_activation_instant_of_the_first_step_is_the_release_start(): void
    {
        $release = Release::factory()->create(['started_at' => now()->subDays(2)]);

        ReleaseStep::factory()->for($release)->active()->create(['position' => 1]);

        $step = ReleaseStep::query()->withActivationInstant()->with('release')->firstOrFail();

        $this->assertTrue($release->started_at->equalTo($step->activationInstant()));
    }

    public function test_the_activation_instant_of_a_later_step_is_the_closure_of_the_previous_one(): void
    {
        // I due istanti coincidono per costruzione: `CloseStep` chiude il
        // precedente e attiva questo nella stessa transazione.
        $release = Release::factory()->create(['started_at' => now()->subDays(5)]);
        // `startOfSecond()` e necessario, non cosmetico: la colonna conserva i
        // secondi e non i microsecondi, quindi un istante con frazione tornerebbe
        // troncato e il confronto fallirebbe su un dato in realta corretto.
        $handover = now()->subHours(4)->startOfSecond();

        ReleaseStep::factory()->for($release)->completed()->create(['position' => 1, 'completed_at' => now()->subDays(3)]);
        ReleaseStep::factory()->for($release)->completed()->create(['position' => 2, 'completed_at' => $handover]);
        $open = ReleaseStep::factory()->for($release)->active()->create(['position' => 3]);

        $step = ReleaseStep::query()->withActivationInstant()->with('release')->whereKey($open->id)->firstOrFail();

        // Il precedente e quello di posizione 2, non il primo della catena: la
        // sottoquery ordina per posizione decrescente e ne prende uno solo.
        $this->assertTrue($handover->equalTo($step->activationInstant()));
    }

    public function test_the_activation_instant_refuses_to_guess_without_its_scope(): void
    {
        // Un ripiego silenzioso direbbe "aperto da 5 giorni" su uno step aperto da
        // quattro ore, e nessuno lo noterebbe guardando la schermata.
        $release = Release::factory()->create(['started_at' => now()->subDays(5)]);

        ReleaseStep::factory()->for($release)->completed()->create(['position' => 1, 'completed_at' => now()->subHours(4)]);
        $open = ReleaseStep::factory()->for($release)->active()->create(['position' => 2]);

        $this->expectException(\LogicException::class);

        $open->activationInstant();
    }

    public function test_the_activation_instant_does_not_cost_a_query_per_row(): void
    {
        $release = Release::factory()->create();

        for ($position = 1; $position <= 5; $position++) {
            ReleaseStep::factory()->for($release)->completed()->create([
                'position' => $position,
                'completed_at' => now()->subHours(10 - $position),
            ]);
        }

        $steps = ReleaseStep::query()->withActivationInstant()->with('release')->ordered()->get();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $instants = $steps->map(fn (ReleaseStep $step): string => $step->activationInstant()->toIso8601String());

        $this->assertCount(5, $instants);
        $this->assertSame(0, $queries, "La lettura degli istanti ha eseguito {$queries} query: la sottoquery non e stata applicata.");
    }

    public function test_the_activation_instant_scope_keeps_the_whole_row(): void
    {
        // Senza `select('release_steps.*')` la sola `addSelect` ridurrebbe la query
        // a quell'unica colonna, e la schermata resterebbe senza nome ne ruolo.
        $created = ReleaseStep::factory()->active()->create(['position' => 1]);

        $step = ReleaseStep::query()->withActivationInstant()->firstOrFail();

        $this->assertSame($created->name, $step->name);
        $this->assertSame($created->role_name, $step->role_name);
        $this->assertSame(ReleaseStepStatus::Active, $step->status);
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
