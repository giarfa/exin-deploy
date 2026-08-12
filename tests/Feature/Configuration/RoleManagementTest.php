<?php

namespace Tests\Feature\Configuration;

use App\Models\DefaultRoleAssignment;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_page_lists_the_role_catalogue(): void
    {
        $role = Role::factory()->create(['name' => 'Dev Lead']);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertSee(__('roles.heading'))
            ->assertSee($role->name);
    }

    public function test_an_administrator_creates_a_role(): void
    {
        Livewire::test('roles.index')
            ->call('openCreateForm')
            ->set('name', 'Release Manager')
            ->set('description', 'Presidia il processo e autorizza il passaggio finale.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('roles', [
            'name' => 'Release Manager',
            'is_active' => true,
        ]);
    }

    public function test_creation_rejects_a_duplicate_name(): void
    {
        Role::factory()->create(['name' => 'QA']);

        Livewire::test('roles.index')
            ->call('openCreateForm')
            ->set('name', 'QA')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, Role::where('name', 'QA')->count());
    }

    public function test_creation_requires_a_name(): void
    {
        Livewire::test('roles.index')
            ->call('openCreateForm')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_an_administrator_edits_a_role(): void
    {
        $role = Role::factory()->create(['name' => 'Sicurezza']);

        Livewire::test('roles.index')
            ->call('openEditForm', $role->id)
            ->set('name', 'Security')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Security', $role->fresh()->name);
    }

    public function test_an_administrator_deactivates_and_reactivates_a_role(): void
    {
        $role = Role::factory()->create();

        Livewire::test('roles.index')->call('toggleActivation', $role->id);
        $this->assertFalse($role->fresh()->is_active);

        Livewire::test('roles.index')->call('toggleActivation', $role->id);
        $this->assertTrue($role->fresh()->is_active);
    }

    public function test_a_free_role_is_deleted(): void
    {
        $role = Role::factory()->create();

        Livewire::test('roles.index')->call('delete', $role->id);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_a_referenced_role_is_not_deleted_and_the_reason_is_explicit(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();
        DefaultRoleAssignment::factory()->for($assignment->role)->create();

        $component = Livewire::test('roles.index')
            ->call('delete', $assignment->role_id);

        $this->assertDatabaseHas('roles', ['id' => $assignment->role_id]);

        $message = $component->get('deletionError');
        $this->assertNotNull($message);
        $this->assertStringContainsString($assignment->role->name, $message);
        $this->assertStringContainsString(__('roles.used_default'), $message);
    }

    public function test_a_referenced_role_stays_deactivatable(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();

        Livewire::test('roles.index')->call('toggleActivation', $assignment->role_id);

        $this->assertFalse($assignment->role->fresh()->is_active);
    }

    public function test_a_member_cannot_invoke_the_livewire_actions(): void
    {
        $role = Role::factory()->create();

        $this->actingAs(User::factory()->member()->create());

        Livewire::test('roles.index')->call('openCreateForm')->assertForbidden();
        Livewire::test('roles.index')->call('toggleActivation', $role->id)->assertForbidden();
    }

    public function test_the_listing_does_not_query_per_row(): void
    {
        ProjectRoleAssignment::factory()->count(5)->create();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::test('roles.index')->assertOk();

        // Una query per l'elenco con i conteggi, piu quelle di sessione e utente:
        // il margine copre il contorno, non un conteggio per riga.
        $this->assertLessThan(5, $queries, "Eseguite {$queries} query: sospetto N+1 sull'elenco dei ruoli.");
    }
}
