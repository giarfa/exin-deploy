<?php

use Illuminate\Support\Facades\Route;

/*
 * Nessuna rotta pubblica: lo strumento e interno e ogni pagina richiede
 * autenticazione. Le rotte di accesso, recupero password e verifica in due
 * passaggi sono registrate da Fortify (vedi FortifyServiceProvider).
 */
Route::middleware('auth')->group(function (): void {
    /*
     * Home autenticata. Segnaposto: la schermata di ingresso definitiva e la
     * vista operativa "i miei step" (US-007), che ne sostituira il contenuto.
     */
    Route::view('/', 'home')->name('home');

    /*
     * Sicurezza dell'account. La conferma della password e richiesta perche
     * attivare o disattivare la verifica in due passaggi e un'operazione sensibile
     * (`twoFactorAuthentication.confirmPassword` in config/fortify.php).
     */
    Route::view('/impostazioni/sicurezza', 'settings.two-factor')
        ->middleware('password.confirm')
        ->name('settings.two-factor');
});
