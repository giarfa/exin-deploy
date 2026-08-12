<?php

namespace Tests\Feature\Configuration;

use App\Models\FieldDefinition;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class StepDefinitionManagementTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->template = WorkflowTemplate::factory()->create();
    }

    private function steps(): Testable
    {
        return Livewire::test('templates.steps', ['template' => $this->template]);
    }

    public function test_the_page_lists_the_steps_in_order(): void
    {
        $template = WorkflowTemplate::factory()->withSteps(3)->create();

        $this->get(route('templates.steps', $template))
            ->assertOk()
            ->assertSeeInOrder($template->stepDefinitions()->pluck('name')->all());
    }

    public function test_an_empty_template_says_it_is_not_usable(): void
    {
        $this->get(route('templates.steps', $this->template))
            ->assertOk()
            ->assertSee(__('templates.steps_empty'));
    }

    public function test_an_administrator_adds_a_step_at_the_end_of_the_sequence(): void
    {
        $role = Role::factory()->create();
        StepDefinition::factory()->for($this->template)->create();

        $this->steps()
            ->call('openCreateForm')
            ->set('name', 'Consegna in produzione')
            ->set('instructions', 'Esegui la consegna e verifica che il servizio risponda.')
            ->set('roleId', $role->id)
            ->call('save')
            ->assertHasNoErrors();

        $added = $this->template->stepDefinitions()->where('name', 'Consegna in produzione')->firstOrFail();

        $this->assertSame(2, $added->position);
        $this->assertSame($role->id, $added->role_id);
        $this->assertSame('Esegui la consegna e verifica che il servizio risponda.', $added->instructions);
    }

    public function test_a_step_requires_a_name_and_a_responsible_role(): void
    {
        $this->steps()
            ->call('openCreateForm')
            ->set('name', '')
            ->set('roleId', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'roleId' => 'required']);
    }

    public function test_a_role_outside_the_listed_options_is_rejected(): void
    {
        // Difesa contro l'indicazione per identificativo: un ruolo disattivato e
        // non usato da questo template non e proponibile nemmeno passandone l'id.
        $hidden = Role::factory()->inactive()->create();
        Role::factory()->create();

        $this->steps()
            ->call('openCreateForm')
            ->set('name', 'Passaggio')
            ->set('roleId', $hidden->id)
            ->call('save')
            ->assertHasErrors('roleId');
    }

    public function test_a_deactivated_role_already_used_by_this_template_stays_selectable(): void
    {
        $role = Role::factory()->inactive()->create();
        StepDefinition::factory()->for($this->template)->for($role)->create();

        $this->steps()
            ->call('openCreateForm')
            ->set('name', 'Secondo passaggio')
            ->set('roleId', $role->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $this->template->stepDefinitions()->count());
    }

    public function test_an_administrator_edits_a_step(): void
    {
        $step = StepDefinition::factory()->for($this->template)->create();

        $this->steps()
            ->call('openEditForm', $step->id)
            ->set('name', 'Verifica funzionale')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Verifica funzionale', $step->fresh()->name);
    }

    public function test_reordering_keeps_the_positions_contiguous(): void
    {
        StepDefinition::factory()->count(4)->for($this->template)->create();

        $third = $this->template->stepDefinitions()->where('position', 3)->firstOrFail();

        $this->steps()->call('moveUp', $third->id);

        $this->assertSame(2, $third->fresh()->position);
        $this->assertSame([1, 2, 3, 4], $this->template->stepDefinitions()->pluck('position')->all());
    }

    public function test_deleting_a_step_removes_its_fields_and_closes_the_sequence(): void
    {
        StepDefinition::factory()->count(3)->for($this->template)->create();

        $second = $this->template->stepDefinitions()->where('position', 2)->firstOrFail();
        FieldDefinition::factory()->count(2)->for($second)->create();

        $this->steps()->call('delete', $second->id);

        $this->assertDatabaseMissing('step_definitions', ['id' => $second->id]);
        $this->assertDatabaseMissing('field_definitions', ['step_definition_id' => $second->id]);
        $this->assertSame([1, 2], $this->template->stepDefinitions()->pluck('position')->all());
    }

    public function test_a_step_of_another_template_is_not_reachable_through_the_actions(): void
    {
        $foreign = StepDefinition::factory()->create();

        try {
            $this->steps()->call('delete', $foreign->id);
            $this->fail('Uno step di un altro template non deve essere raggiungibile.');
        } catch (ModelNotFoundException) {
            // Atteso: la ricerca avviene dentro il template della rotta.
        }

        $this->assertDatabaseHas('step_definitions', ['id' => $foreign->id]);
    }

    public function test_a_member_cannot_invoke_the_livewire_actions(): void
    {
        $step = StepDefinition::factory()->for($this->template)->create();

        $this->actingAs(User::factory()->member()->create());

        $this->steps()->call('openCreateForm')->assertForbidden();
        $this->steps()->call('openEditForm', $step->id)->assertForbidden();
        $this->steps()->call('moveUp', $step->id)->assertForbidden();
        $this->steps()->call('moveDown', $step->id)->assertForbidden();
        $this->steps()->call('delete', $step->id)->assertForbidden();
    }

    public function test_the_listing_does_not_query_per_row(): void
    {
        StepDefinition::factory()->count(6)->for($this->template)->create()
            ->each(fn (StepDefinition $step) => FieldDefinition::factory()->count(2)->for($step)->create());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->steps()->assertOk();

        $this->assertLessThan(7, $queries, "Eseguite {$queries} query: sospetto N+1 sull'elenco degli step.");
    }
}
