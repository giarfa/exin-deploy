<?php

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\Release;
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
     * Schermata di ingresso: gli step che attendono chi entra, su tutti i
     * progetti. **Non e una dashboard di grafici**, ed e una scelta di prodotto:
     * senza notifiche (FR-025 fuori perimetro) questa pagina e il posto in cui si
     * scopre che qualcosa e fermo su di te.
     *
     * Il nome `home` resta quello di prima: logo della sidebar, voce di
     * navigazione e redirect di Fortify vi puntano gia, e cambiarlo li avrebbe
     * rotti tutti per rinominare una costante.
     *
     * Nessun `->can()`: non c'e nulla da autorizzare oltre l'autenticazione — la
     * pagina mostra solo cio che e assegnato a chi la guarda.
     */
    Route::livewire('/', 'my-steps.index')->name('home');

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
     *
     * Il parametro si chiama `{stepDefinition}` e non `{step}` perche il binding
     * annidato ne ricava il nome della relazione da interrogare
     * (`stepDefinition` -> `stepDefinitions()`). Il segmento visibile
     * dell'indirizzo resta `step`.
     */
    Route::livewire('/template/{template}/step/{stepDefinition}/campi', 'templates.fields')
        ->can('manageSteps', 'template')
        ->scopeBindings()
        ->name('templates.fields');

    /*
     * Avvio di una release. L'autorizzazione decide sul **progetto** perche al
     * momento del controllo la release non esiste ancora; il doppio livello vale
     * come altrove — il middleware blocca la rotta, la Gate dentro il componente
     * blocca l'azione Livewire, che non ripassa da qui.
     */
    Route::livewire('/progetti/{project}/rilascio', 'releases.start')
        ->can('create', [Release::class, 'project'])
        ->name('releases.start');

    /*
     * Elenco delle release, in corso e concluse, con i filtri per stato e per
     * progetto. E aperto a ogni membro autenticato come il dettaglio: senza
     * notifiche, sapere quali rilasci sono fermi e su chi e la funzione dello
     * strumento (vedi `ReleasePolicy::viewAny`).
     *
     * Il `->can()` c'e, come sul dettaglio e a differenza di `/step/{releaseStep}`:
     * qui non esiste alcun tentativo da registrare nel registro delle transizioni,
     * quindi il middleware puo rifiutare per primo e la protezione resta a due
     * livelli.
     *
     * Registrata **prima** di `/rilasci/{release}`: le rotte statiche precedono
     * quelle con parametro, cosi che l'indirizzo dell'elenco non venga mai risolto
     * come identificativo di una release.
     */
    Route::livewire('/rilasci', 'releases.index')
        ->can('viewAny', Release::class)
        ->name('releases.index');

    /*
     * Dettaglio di una release: la catena congelata con lo stato di ogni step, i
     * responsabili e le informazioni fornite. Schermata di **sola lettura**.
     *
     * Il `->can()` **c'e**, a differenza di `/step/{releaseStep}`: qui non esiste
     * alcun tentativo da registrare nel registro delle transizioni, quindi il
     * middleware puo rifiutare per primo e la protezione resta a due livelli come
     * su tutte le altre rotte (vedi `.ai/rules/routes.md` per la deroga).
     *
     * `/rilasci/` al plurale come `/progetti/` e `/membri/`: l'indirizzo nomina la
     * collezione, il parametro la riga.
     */
    Route::livewire('/rilasci/{release}', 'releases.show')
        ->can('view', 'release')
        ->name('releases.show');

    /*
     * Compilazione e chiusura di uno step di una release avviata.
     *
     * **Un solo parametro**, e non `/rilasci/{release}/step/{releaseStep}`: lo step
     * ha chiave primaria UUID e la release si raggiunge dalla relazione, quindi la
     * forma annidata non aggiungerebbe sicurezza. Avrebbe invece richiesto
     * `scopeBindings()`, che ricava il nome della relazione dal parametro
     * (`releaseStep` -> `releaseSteps()`): un secondo nome per `Release::steps()`,
     * cioe due nomi per la stessa catena congelata.
     *
     * **Nessun `->can()` sul middleware**, in deroga dichiarata alla protezione a
     * due livelli adottata da tutte le altre rotte di questa applicazione. Il
     * criterio di accettazione chiede che un tentativo non autorizzato sia
     * **registrato** nel log e nel registro delle transizioni, e il middleware
     * rifiuta prima che il codice applicativo possa scrivere quella riga. Il
     * controllo resta pieno e vive nel componente, dove `authorizeOrRecord()`
     * registra e poi rifiuta con 403 — al montaggio e su ogni azione.
     *
     * Senza questo commento la prossima revisione leggerebbe l'assenza del `->can()`
     * come una dimenticanza, e aggiungerlo spegnerebbe il tracciamento.
     */
    Route::livewire('/step/{releaseStep}', 'releases.step')
        ->name('releases.step');

    Route::livewire('/responsabili-predefiniti', 'default-assignments.index')
        ->can('viewAny', DefaultRoleAssignment::class)
        ->name('default-assignments.index');
});
