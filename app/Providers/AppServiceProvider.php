<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        $this->registerPasswordDefaults();
    }

    /**
     * Regole password globali per l'intera applicazione.
     *
     * Vengono applicate da ogni validazione che usa `Password::default()`,
     * incluse le action di Fortify (reset e aggiornamento password).
     */
    private function registerPasswordDefaults(): void
    {
        Password::defaults(fn (): Password => Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised());
    }
}
