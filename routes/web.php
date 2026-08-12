<?php

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowTemplate;
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

    /*
     * Gestione dei membri. L'autorizzazione e applicata due volte, e non e
     * ridondanza: il middleware blocca la rotta, le Gate dentro il componente
     * blocca le singole azioni Livewire, che non ripassano da qui.
     */
    Route::livewire('/membri', 'members.index')
        ->can('viewAny', User::class)
        ->name('members.index');

    /*
     * Configurazione del processo: ruoli funzionali, progetti e mappature
     * ruolo -> persona. Superficie riservata agli amministratori, protetta due
     * volte come la gestione dei membri.
     */
    Route::livewire('/ruoli', 'roles.index')
        ->can('viewAny', Role::class)
        ->name('roles.index');

    Route::livewire('/progetti', 'projects.index')
        ->can('viewAny', Project::class)
        ->name('projects.index');

    /*
     * Il binding e sull'identificativo e non sullo slug (vedi Project::getRouteKeyName):
     * lo slug e modificabile e un collegamento salvato si romperebbe alla rinomina.
     */
    Route::livewire('/progetti/{project}/responsabili', 'projects.assignments')
        ->can('manageAssignments', 'project')
        ->name('projects.assignments');

    /*
     * Template di workflow: la definizione del processo di rilascio. Tre livelli
     * annidati — template, step, campi richiesti — perche in un unico componente
     * i tre livelli di form diventerebbero illeggibili a 375 px.
     *
     * Step e campi non hanno una Policy propria: sono decisi da `manageSteps` sul
     * template che li contiene.
     */
    Route::livewire('/template', 'templates.index')
        ->can('viewAny', WorkflowTemplate::class)
        ->name('templates.index');

    Route::livewire('/template/{template}/step', 'templates.steps')
        ->can('manageSteps', 'template')
        ->name('templates.steps');

    /*
     * `scopeBindings()` non e cosmetico: senza, uno step appartenente a un altro
     * template sarebbe raggiungibile cambiando identificativo nell'indirizzo,
     * anche con l'autorizzazione sul template corretta.
     */
    Route::livewire('/template/{template}/step/{step}/campi', 'templates.fields')
        ->can('manageSteps', 'template')
        ->scopeBindings()
        ->name('templates.fields');

    Route::livewire('/responsabili-predefiniti', 'default-assignments.index')
        ->can('viewAny', DefaultRoleAssignment::class)
        ->name('default-assignments.index');
});
