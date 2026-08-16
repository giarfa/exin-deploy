<?php

namespace Tests\Unit\Models;

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_receives_a_uuid_primary_key(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUuid($user->id), "L'id [{$user->id}] non e un UUID valido.");
    }

    public function test_it_casts_the_level_to_an_enum(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertInstanceOf(UserLevel::class, $user->fresh()->level);
        $this->assertSame(UserLevel::Admin, $user->fresh()->level);
    }

    public function test_only_an_admin_is_recognised_as_administrator(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->isAdministrator());
        $this->assertFalse(User::factory()->member()->create()->isAdministrator());
    }

    public function test_the_inactive_state_disables_the_member(): void
    {
        $user = User::factory()->inactive()->create();

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_a_member_is_active_by_default(): void
    {
        $this->assertTrue(User::factory()->create()->fresh()->is_active);
    }

    public function test_the_password_is_hashed_with_argon2id(): void
    {
        $user = User::factory()->create(['password' => 'Rilascio-2026!']);

        $this->assertTrue(Hash::check('Rilascio-2026!', $user->password));
        $this->assertStringStartsWith(
            '$argon2id$',
            $user->password,
            'La password non e hashata con Argon2id: la baseline di sicurezza esclude bcrypt.'
        );
    }

    public function test_it_hides_credentials_and_two_factor_secrets_from_serialization(): void
    {
        $serialized = User::factory()->create()->toArray();

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $serialized);
        }
    }
}
