<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_authentication_is_enabled_as_a_fortify_feature(): void
    {
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));
        $this->assertTrue(Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'));
    }

    public function test_passkeys_are_not_enabled(): void
    {
        $this->assertFalse(
            Features::enabled(Features::passkeys()),
            'Le passkey sono fuori dal perimetro del PRD e devono restare disattivate.'
        );
    }

    public function test_a_member_can_enable_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'))
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNull(
            $user->two_factor_confirmed_at,
            'Con confirm=true il 2FA non e attivo finche non viene confermato.'
        );
    }

    public function test_the_enrolment_exposes_a_qr_code_and_the_manual_secret(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'));

        $this->actingAs($user->fresh())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('settings.two-factor'))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee(__('app.two_factor_secret'));
    }

    public function test_a_valid_code_confirms_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'));

        $user->refresh();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.confirm'), ['code' => $this->currentCodeFor($user)])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_a_wrong_confirmation_code_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'));

        $this->actingAs($user->fresh())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors();

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_login_requires_the_two_factor_challenge_once_confirmed(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_the_challenge_offers_the_switch_between_code_and_recovery_code(): void
    {
        /*
         * Il comando di scambio era reso **senza testo**: `@js()` dentro `x-text`
         * su un componente Blade non viene compilato e l'espressione Alpine
         * arrivava al browser come stringa vuota di significato. Chi aveva perso
         * il telefono si trovava davanti a un collegamento invisibile — e nessun
         * test se ne accorgeva, perche il percorso del codice di recupero passa
         * dalla POST e non dalla pagina.
         *
         * Entrambe le etichette sono nel DOM di proposito: Alpine sceglie quale
         * mostrare, senza costruire stringhe JavaScript attorno a un apice.
         */
        $user = $this->userWithConfirmedTwoFactor();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ]);

        $response = $this->get(route('two-factor.login'))->assertOk();

        $response->assertSee(__('auth.two_factor_use_recovery'));
        $response->assertSee(__('auth.two_factor_use_code'));
        $response->assertDontSee('@js(', false);
    }

    public function test_the_challenge_accepts_a_valid_totp_code(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ]);

        $this->post(route('two-factor.login.store'), ['code' => $this->currentCodeFor($user)])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_challenge_rejects_an_invalid_totp_code(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ]);

        $this->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    /**
     * Fortify memorizza i codici gia consumati (`fortify.2fa_codes.*`) e
     * `verifyKeyNewer` ne rifiuta il riutilizzo: un codice intercettato non e
     * riusabile nemmeno entro la stessa finestra temporale di 30 secondi.
     */
    public function test_a_code_cannot_be_replayed(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $code = $this->currentCodeFor($user);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ]);
        $this->post(route('two-factor.login.store'), ['code' => $code])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Rilascio-2026!',
        ]);

        $this->post(route('two-factor.login.store'), ['code' => $code])->assertSessionHasErrors();
        $this->assertGuest();
    }

    /**
     * Codice TOTP valido nella finestra corrente per il membro indicato.
     */
    private function currentCodeFor(User $user): string
    {
        /** @var string $secret */
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    /**
     * Membro con verifica in due passaggi gia attiva e confermata.
     *
     * Lo stato viene predisposto direttamente sul modello: il percorso di
     * enrolment e conferma ha i propri test dedicati, e riusare qui un codice
     * TOTP lo renderebbe inutilizzabile per il challenge (protezione da replay).
     */
    private function userWithConfirmedTwoFactor(): User
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        $user = User::factory()->create([
            'password' => 'Rilascio-2026!',
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recupero-uno', 'recupero-due'])),
            'two_factor_confirmed_at' => now(),
        ]);

        Cache::flush();

        return $user->fresh();
    }
}
