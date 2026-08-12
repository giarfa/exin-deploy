<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_reset_request_screen_is_reachable(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee(__('auth.forgot_heading'));
    }

    public function test_a_reset_link_is_sent_for_a_known_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Nuova-Password-2026!',
                'password_confirmation' => 'Nuova-Password-2026!',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertTrue(Hash::check('Nuova-Password-2026!', $user->fresh()->password));
    }

    public function test_the_reset_rejects_a_password_that_violates_the_global_rules(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => 'Rilascio-2026!']);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertSessionHasErrors('password');

            return true;
        });

        $this->assertTrue(
            Hash::check('Rilascio-2026!', $user->fresh()->password),
            'La password non doveva essere modificata da una richiesta non valida.'
        );
    }
}
