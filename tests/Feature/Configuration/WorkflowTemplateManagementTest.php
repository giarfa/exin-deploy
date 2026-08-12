<?php

namespace Tests\Feature\Configuration;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_page_lists_the_templates(): void
    {
        $template = WorkflowTemplate::factory()->create(['name' => 'Rilascio standard']);

        $this->get(route('templates.index'))
            ->assertOk()
            ->assertSee(__('templates.heading'))
            ->assertSee($template->name);
    }

    public function test_an_administrator_creates_a_template(): void
    {
        Livewire::test('templates.index')
            ->call('openCreateForm')
            ->set('name', 'Rilascio infrastrutturale')
            ->set('description', 'Interventi su ambienti e infrastruttura.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('workflow_templates', [
            'name' => 'Rilascio infrastrutturale',
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    public function test_creation_rejects_a_duplicate_name(): void
    {
        WorkflowTemplate::factory()->create(['name' => 'Rilascio standard']);

        Livewire::test('templates.index')
            ->call('openCreateForm')
            ->set('name', 'Rilascio standard')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, WorkflowTemplate::where('name', 'Rilascio standard')->count());
    }

    public function test_an_administrator_edits_and_deactivates_a_template(): void
    {
        $template = WorkflowTemplate::factory()->create(['name' => 'Rilascio veloce']);

        Livewire::test('templates.index')
            ->call('openEditForm', $template->id)
            ->set('name', 'Rilascio urgente')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Rilascio urgente', $template->fresh()->name);

        Livewire::test('templates.index')->call('toggleActivation', $template->id);

        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_setting_a_default_goes_through_the_action_and_clears_the_previous(): void
    {
        $previous = WorkflowTemplate::factory()->isDefault()->create();
        $next = WorkflowTemplate::factory()->create();

        Livewire::test('templates.index')->call('setAsDefault', $next->id);

        $this->assertTrue($next->fresh()->is_default);
        $this->assertFalse($previous->fresh()->is_default);
    }

    public function test_a_deactivated_template_is_refused_as_default_with_an_explicit_reason(): void
    {
        $template = WorkflowTemplate::factory()->inactive()->create();

        $component = Livewire::test('templates.index')->call('setAsDefault', $template->id);

        $this->assertFalse($template->fresh()->is_default);
        $this->assertSame(__('templates.default_requires_active'), $component->get('operationError'));
    }

    public function test_deactivating_the_default_template_removes_the_flag(): void
    {
        $template = WorkflowTemplate::factory()->isDefault()->create();

        Livewire::test('templates.index')->call('toggleActivation', $template->id);

        $this->assertFalse($template->fresh()->is_active);
        $this->assertFalse($template->fresh()->is_default);
    }

    public function test_a_template_without_steps_is_flagged_as_unusable(): void
    {
        WorkflowTemplate::factory()->create(['name' => 'Processo incompleto']);

        $this->get(route('templates.index'))
            ->assertOk()
            ->assertSee(__('templates.unusable_without_steps'));
    }

    public function test_a_member_cannot_invoke_the_livewire_actions(): void
    {
        $template = WorkflowTemplate::factory()->create();

        $this->actingAs(User::factory()->member()->create());

        Livewire::test('templates.index')->call('openCreateForm')->assertForbidden();
        Livewire::test('templates.index')->call('openEditForm', $template->id)->assertForbidden();
        Livewire::test('templates.index')->call('toggleActivation', $template->id)->assertForbidden();
        Livewire::test('templates.index')->call('setAsDefault', $template->id)->assertForbidden();
    }

    public function test_a_member_refused_on_a_deactivated_template_gets_a_403_not_a_domain_message(): void
    {
        // Il rifiuto di dominio non deve mascherare un difetto di autorizzazione.
        $template = WorkflowTemplate::factory()->inactive()->create();

        $this->actingAs(User::factory()->member()->create());

        Livewire::test('templates.index')->call('setAsDefault', $template->id)->assertForbidden();
    }

    public function test_nobody_can_delete_a_template(): void
    {
        $template = WorkflowTemplate::factory()->create();

        $this->assertFalse(User::factory()->admin()->create()->can('delete', $template));
        $this->assertFalse(User::factory()->member()->create()->can('delete', $template));
    }

    public function test_the_listing_does_not_query_per_row(): void
    {
        WorkflowTemplate::factory()->count(5)->withSteps(3)->create()
            ->each(fn (WorkflowTemplate $template) => Project::factory()->withTemplate($template)->create());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::test('templates.index')->assertOk();

        // Una query per l'elenco con i conteggi, piu quelle di sessione e utente:
        // il margine copre il contorno, non un conteggio per riga.
        $this->assertLessThan(5, $queries, "Eseguite {$queries} query: sospetto N+1 sull'elenco dei template.");
    }
}
