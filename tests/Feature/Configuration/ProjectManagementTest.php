<?php

namespace Tests\Feature\Configuration;

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
