<?php

namespace Tests\Feature\Members;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_manage_members(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('update', $member));
        $this->assertTrue($admin->can('view', $member));
    }

    public function test_a_member_cannot_manage_members(): void
    {
        $member = User::factory()->member()->create();
        $other = User::factory()->member()->create();

        $this->assertFalse($member->can('viewAny', User::class));
        $this->assertFalse($member->can('create', User::class));
        $this->assertFalse($member->can('update', $other));
        $this->assertFalse($member->can('view', $other));
    }

    public function test_everyone_can_view_their_own_profile(): void
    {
        $member = User::factory()->member()->create();

        $this->assertTrue($member->can('view', $member));
    }

    /**
     * Il filtro `before()` non deve annullare i vincoli che valgono anche per
     * gli amministratori: e la parte che una implementazione affrettata sbaglia.
     */
    public function test_an_administrator_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertFalse(
            $admin->can('toggleActivation', $admin),
            'Un amministratore che si disattiva si escluderebbe senza possibilita di rientro.'
        );
    }

    public function test_an_administrator_can_deactivate_another_member(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->assertTrue($admin->can('toggleActivation', $member));
    }

    public function test_a_member_cannot_deactivate_anyone(): void
    {
        $member = User::factory()->member()->create();

        $this->assertFalse($member->can('toggleActivation', User::factory()->member()->create()));
        $this->assertFalse($member->can('toggleActivation', $member));
    }

    /**
     * La cancellazione non e prevista per nessuno: i membri si disattivano,
     * perche la loro traccia sui rilasci passati deve restare leggibile (FR-016).
     */
    public function test_nobody_can_delete_a_member(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->assertFalse($admin->can('delete', $member));
        $this->assertFalse($member->can('delete', $member));
    }
}
