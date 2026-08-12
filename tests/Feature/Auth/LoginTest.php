<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_is_reachable_by_a_guest(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('auth.login_heading'));
    }

    public function test_a_member_can_authenticate_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Rilascio-2026!']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ])->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_authentication_fails_with_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'Rilascio-2026!']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password-sbagliata',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authentication_fails_for_an_unknown_email(): void
    {
        $this->post(route('login.store'), [
            'email' => 'nessuno@gruppoexcellence.com',
            'password' => 'Rilascio-2026!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Il caso che una implementazione plausibile ma sbagliata lascerebbe passare:
     * credenziali corrette ma account disattivato.
     */
    public function test_a_deactivated_member_cannot_authenticate_even_with_the_right_password(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'Rilascio-2026!']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ])->assertSessionHasErrors(['email' => __('auth.inactive')]);

        $this->assertGuest();
    }

    public function test_the_deactivated_member_record_is_preserved(): void
    {
        $user = User::factory()->inactive()->create();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => false,
        ]);
    }

    public function test_public_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_a_guest_is_redirected_from_an_application_route(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('settings.two-factor'))->assertRedirect(route('login'));
    }

    public function test_an_authenticated_member_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();
    }
}
