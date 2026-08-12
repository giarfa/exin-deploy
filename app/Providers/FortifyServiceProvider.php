<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::viewPrefix('auth.');

        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->authenticateOnlyActiveMembers();

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }

    /**
     * Consente l'accesso ai soli membri attivi.
     *
     * Un membro disattivato non e un tentativo di intrusione ma un caso legittimo,
     * quindi riceve un messaggio dedicato invece di quello generico sulle
     * credenziali errate. Il record non viene mai cancellato: la sua traccia sui
     * rilasci passati deve restare leggibile nel registro (FR-016).
     */
    private function authenticateOnlyActiveMembers(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            /** @var string $email */
            $email = $request->input(Fortify::username(), '');
            /** @var string $password */
            $password = $request->input('password', '');

            $user = User::where('email', $email)->first();

            if (! $user || ! Hash::check($password, $user->password)) {
                Log::warning('Tentativo di accesso non riuscito.', [
                    'email' => $email,
                    'ip' => $request->ip(),
                ]);

                return null;
            }

            if (! $user->is_active) {
                Log::warning('Tentativo di accesso di un membro disattivato.', [
                    'user_id' => $user->id,
                    'ip' => $request->ip(),
                ]);

                throw ValidationException::withMessages([
                    Fortify::username() => __('auth.inactive'),
                ]);
            }

            return $user;
        });
    }
}
