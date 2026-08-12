<?php

namespace Tests\Feature\Configuration;

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_page_lists_the_projects(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee(__('projects.heading'))
            ->assertSee($project->name)
            ->assertSee($project->slug);
    }

    public function test_an_administrator_creates_a_project_and_the_slug_follows_the_name(): void
    {
        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->set('name', 'Portale Fornitori')
            ->assertSet('slug', 'portale-fornitori')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Portale Fornitori',
            'slug' => 'portale-fornitori',
            'is_active' => true,
        ]);
    }

    public function test_a_new_project_is_precompiled_with_the_default_mapping(): void
    {
        $defaults = DefaultRoleAssignment::factory()->count(3)->create();

        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->set('name', 'Portale Fornitori')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('skippedRoles', 0);

        $project = Project::firstWhere('slug', 'portale-fornitori');

        $this->assertNotNull($project);
        $this->assertCount(3, $project->assignments);
        $this->assertEqualsCanonicalizing(
            $defaults->pluck('user_id')->all(),
            $project->assignments->pluck('user_id')->all(),
        );
    }

    public function test_the_uncovered_roles_are_reported_after_creation(): void
    {
        DefaultRoleAssignment::factory()->create();
        DefaultRoleAssignment::factory()
            ->for(User::factory()->member()->inactive())
            ->create();

        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->set('name', 'Portale Fornitori')
            ->call('save')
            ->assertSet('skippedRoles', 1);
    }

    public function test_creation_rejects_a_duplicate_slug(): void
    {
        Project::factory()->create(['slug' => 'portale-clienti']);

        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->set('name', 'Altro progetto')
            ->set('slug', 'portale-clienti')
            ->call('save')
            ->assertHasErrors(['slug' => 'unique']);

        $this->assertSame(1, Project::where('slug', 'portale-clienti')->count());
    }

    public function test_creation_rejects_a_malformed_slug(): void
    {
        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->set('name', 'Portale Fornitori')
            ->set('slug', 'Portale Fornitori!')
            ->call('save')
            ->assertHasErrors(['slug' => 'regex']);
    }

    public function test_editing_a_project_keeps_its_own_slug_valid(): void
    {
        $project = Project::factory()->create(['slug' => 'portale-clienti']);

        Livewire::test('projects.index')
            ->call('openEditForm', $project->id)
            ->set('name', 'Portale Clienti Rinnovato')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Portale Clienti Rinnovato', $project->fresh()->name);
        $this->assertSame('portale-clienti', $project->fresh()->slug);
    }

    public function test_an_administrator_deactivates_and_reactivates_a_project(): void
    {
        $project = Project::factory()->create();

        Livewire::test('projects.index')->call('toggleActivation', $project->id);
        $this->assertFalse($project->fresh()->is_active);

        Livewire::test('projects.index')->call('toggleActivation', $project->id);
        $this->assertTrue($project->fresh()->is_active);
    }

    public function test_no_delete_action_is_exposed(): void
    {
        Project::factory()->create();

        $component = Livewire::test('projects.index');

        // Nessuna azione di cancellazione nel componente, e la ragione dichiarata
        // in chiaro nella schermata: i progetti contengono lo storico dei rilasci.
        $this->assertFalse(method_exists($component->instance(), 'delete'));
        $component->assertSee(__('projects.no_deletion_note'));
    }

    public function test_a_member_cannot_invoke_the_livewire_actions(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->member()->create());

        Livewire::test('projects.index')->call('openCreateForm')->assertForbidden();
        Livewire::test('projects.index')->call('toggleActivation', $project->id)->assertForbidden();
    }

    public function test_the_default_template_is_proposed_and_stays_replaceable(): void
    {
        $default = WorkflowTemplate::factory()->isDefault()->withSteps(2)->create();
        $other = WorkflowTemplate::factory()->withSteps(2)->create();

        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->assertSet('workflowTemplateId', $default->id)
            ->set('name', 'Portale Clienti')
            ->set('slug', 'portale-clienti')
            ->set('workflowTemplateId', $other->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($other->id, Project::where('slug', 'portale-clienti')->value('workflow_template_id'));
    }

    public function test_a_project_can_be_created_without_a_template(): void
    {
        WorkflowTemplate::factory()->isDefault()->withSteps(2)->create();

        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->set('name', 'Senza processo')
            ->set('slug', 'senza-processo')
            ->set('workflowTemplateId', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Project::where('slug', 'senza-processo')->value('workflow_template_id'));
    }

    public function test_a_template_outside_the_listed_options_is_rejected(): void
    {
        // Un template disattivato e non associato a questo progetto non e
        // scegliibile nemmeno indicandone l'identificativo.
        $hidden = WorkflowTemplate::factory()->inactive()->create();

        Livewire::test('projects.index')
            ->call('openCreateForm')
            ->set('name', 'Progetto')
            ->set('slug', 'progetto')
            ->set('workflowTemplateId', $hidden->id)
            ->call('save')
            ->assertHasErrors('workflowTemplateId');
    }

    public function test_the_listing_names_the_template_and_flags_uncovered_roles(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $roles = Role::factory()->count(2)->create();

        foreach ($roles as $role) {
            StepDefinition::factory()->for($template)->for($role)->create();
        }

        $project = Project::factory()->withTemplate($template)->create();
        ProjectRoleAssignment::factory()->for($project)->for($roles[0])->create();

        Livewire::test('projects.index')
            ->assertSee($template->name)
            ->assertSee(trans_choice('projects.uncovered_roles_badge', 1, ['count' => 1]));
    }

    public function test_a_fully_mapped_project_shows_no_uncovered_roles_badge(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $role = Role::factory()->create();
        StepDefinition::factory()->for($template)->for($role)->create();

        $project = Project::factory()->withTemplate($template)->create();
        ProjectRoleAssignment::factory()->for($project)->for($role)->create();

        Livewire::test('projects.index')
            ->assertDontSee(trans_choice('projects.uncovered_roles_badge', 1, ['count' => 1]));
    }

    public function test_the_listing_does_not_query_per_row(): void
    {
        $template = WorkflowTemplate::factory()->withSteps(3)->create();
        Project::factory()->count(6)->withTemplate($template)->create();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::test('projects.index')->assertOk();

        // Elenco, conteggi, template, step, ruoli e assegnazioni: un numero fisso
        // di query, che non deve crescere con il numero di progetti.
        $this->assertLessThan(11, $queries, "Eseguite {$queries} query: sospetto N+1 sull'elenco dei progetti.");
    }
}
