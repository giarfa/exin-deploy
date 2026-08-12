<?php

namespace Tests\Feature\Members;

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_reaches_the_members_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee(__('members.heading'));
    }

    public function test_a_member_is_forbidden_from_the_members_page(): void
    {
        $this->actingAs(User::factory()->member()->create())
            ->get(route('members.index'))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('members.index'))->assertRedirect(route('login'));
    }

    public function test_an_administrator_creates_a_member(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('members.index')
            ->call('openCreateForm')
            ->set('name', 'Giulia Rinaldi')
            ->set('email', 'g.rinaldi@gruppoexcellence.com')
            ->set('level', UserLevel::Member->value)
            ->set('password', 'Rilascio-2026!')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::where('email', 'g.rinaldi@gruppoexcellence.com')->first();

        $this->assertNotNull($created);
        $this->assertSame('Giulia Rinaldi', $created->name);
        $this->assertSame(UserLevel::Member, $created->level);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('Rilascio-2026!', $created->password));
    }

    public function test_creation_rejects_a_duplicate_email(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $existing = User::factory()->create();

        Livewire::test('members.index')
            ->call('openCreateForm')
            ->set('name', 'Omonimo')
            ->set('email', $existing->email)
            ->set('level', UserLevel::Member->value)
            ->set('password', 'Rilascio-2026!')
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_creation_rejects_a_weak_password(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('members.index')
            ->call('openCreateForm')
            ->set('name', 'Password Debole')
            ->set('email', 'debole@gruppoexcellence.com')
            ->set('level', UserLevel::Member->value)
            ->set('password', 'password')
            ->call('save')
            ->assertHasErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'debole@gruppoexcellence.com']);
    }

    public function test_creation_rejects_a_level_outside_the_enum(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('members.index')
            ->call('openCreateForm')
            ->set('name', 'Livello Inventato')
            ->set('email', 'livello@gruppoexcellence.com')
            ->set('level', 'superuser')
            ->set('password', 'Rilascio-2026!')
            ->call('save')
            ->assertHasErrors(['level']);
    }

    public function test_an_administrator_updates_a_member_without_changing_the_password(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $member = User::factory()->member()->create(['password' => 'Rilascio-2026!']);

        Livewire::test('members.index')
            ->call('openEditForm', $member->id)
            ->set('name', 'Nome Corretto')
            ->set('level', UserLevel::Admin->value)
            ->call('save')
            ->assertHasNoErrors();

        $member->refresh();

        $this->assertSame('Nome Corretto', $member->name);
        $this->assertSame(UserLevel::Admin, $member->level);
        $this->assertTrue(
            Hash::check('Rilascio-2026!', $member->password),
            'Con il campo password vuoto la password non deve cambiare.'
        );
    }

    public function test_an_administrator_deactivates_a_member(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $member = User::factory()->member()->create();

        Livewire::test('members.index')
            ->call('toggleActivation', $member->id)
            ->assertHasNoErrors();

        $this->assertFalse($member->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_a_deactivated_member_can_be_reactivated(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $member = User::factory()->inactive()->create();

        Livewire::test('members.index')->call('toggleActivation', $member->id);

        $this->assertTrue($member->fresh()->is_active);
    }

    /**
     * Le azioni Livewire non ripassano dal middleware della rotta: l'autorizzazione
     * dentro il componente e l'unica barriera, e va verificata separatamente.
     */
    public function test_a_member_cannot_invoke_the_livewire_actions(): void
    {
        $this->actingAs(User::factory()->member()->create());
        $target = User::factory()->member()->create();

        Livewire::test('members.index')->call('openCreateForm')->assertForbidden();
        Livewire::test('members.index')->call('openEditForm', $target->id)->assertForbidden();
        Livewire::test('members.index')->call('toggleActivation', $target->id)->assertForbidden();
    }

    public function test_an_administrator_cannot_deactivate_themselves_through_the_component(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test('members.index')
            ->call('toggleActivation', $admin->id)
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_a_deactivated_member_stays_visible_in_the_list(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $member = User::factory()->inactive()->create(['name' => 'Membro Archiviato']);

        $this->get(route('members.index'))
            ->assertOk()
            ->assertSee('Membro Archiviato')
            ->assertSee(__('members.inactive'));
    }

    public function test_no_delete_action_is_exposed(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertFalse(
            method_exists(Livewire::test('members.index')->instance(), 'delete'),
            'Il componente non deve esporre alcuna azione di cancellazione dei membri.'
        );
    }
}
