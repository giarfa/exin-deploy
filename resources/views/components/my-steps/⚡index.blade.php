<?php

use App\Enums\ReleaseStepStatus;
use App\Models\Release;
use App\Models\ReleaseStep;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Schermata di ingresso: gli step che attendono chi e appena entrato, su tutti i
 * progetti, piu le release in corso su cui e coinvolto senza avere il turno.
 *
 * **Non e una dashboard.** La prima cosa che si vede entrando e cio che aspetta
 * una persona, non un grafico: e la prima delle due mitigazioni progettuali del
 * rischio accettato n.1 del PRD (assenza di notifiche, FR-025 fuori perimetro).
 * La seconda e il blocco delle release in attesa, che dice **chi** trattiene il
 * flusso e **da quanto** — chiunque puo quindi sollecitare, invece di scoprire il
 * blocco a valle.
 *
 * Il filtro e **sull'assegnazione**, non sulla Policy: `ReleaseStepPolicy` concede
 * a un amministratore la lettura di qualunque step, ma questa schermata si chiama
 * "i miei step" e mostrargli anche quelli altrui la trasformerebbe in un cruscotto
 * di sorveglianza, seppellendo cio che attende davvero lui.
 *
 * Due sole query di dominio, entrambe sullo snapshot congelato (`release_steps`,
 * `releases`): nessuna lettura di `step_definitions` o `field_definitions`, come
 * impone la regola portante del progetto.
 */
new class extends Component
{
    /**
     * Step aperti in carico a chi sta guardando, dal piu vecchio.
     *
     * **Ordinamento in PHP e non in SQL**, ed e una scelta con un limite noto.
     * L'istante di attivazione e `previous_step_completed_at` oppure, sul primo
     * della catena, `release.started_at`: ordinarlo in database richiederebbe un
     * `ORDER BY COALESCE(<sottoquery>, releases.started_at)` con un join sulle
     * release. E fattibile e portabile — non e vero che sarebbe obbligato ordinare
     * sulla colonna nuda, dove la posizione dei `NULL` cambia da motore a motore —
     * ma qui non paga: l'insieme e quello che **una persona sola** tiene aperto,
     * gia interamente caricato, e riordinarlo in memoria non costa una query.
     *
     * Il limite che questa scelta comporta va detto: cosi il blocco **non e
     * paginabile ne limitabile in database**. Va bene per "i miei step"; il giorno
     * in cui servisse un elenco esteso o le metriche di processo (FR-024), l'ordine
     * va spostato in SQL insieme al resto.
     *
     * Chiave composita e non tre `sortBy` annidati: il formato `Y-m-d H:i:s` e
     * ordinabile lessicograficamente, quindi progetto ed etichetta valgono come
     * spareggio deterministico senza riordini successivi.
     *
     * @return \Illuminate\Support\Collection<int, ReleaseStep>
     */
    #[Computed]
    public function mySteps()
    {
        return ReleaseStep::query()
            ->awaitingUser(auth()->user())
            ->withActivationInstant()
            // `withCount('steps')` da il denominatore di "Step 2 di 5": senza,
            // ogni card conterebbe la propria catena con una query.
            ->with(['release' => fn ($release) => $release->with('project')->withCount('steps')])
            ->get()
            ->sortBy(fn (ReleaseStep $step): string => implode('|', [
                $step->activationInstant()->format('Y-m-d H:i:s'),
                $step->release->project->name,
                $step->release->label,
            ]))
            ->values();
    }

    /**
     * Release in corso che coinvolgono chi sta guardando ma sono ferme su
     * qualcun altro.
     *
     * `whereDoesntHave` esclude quelle gia presenti nel primo blocco: se il turno
     * e tuo, la release non e "in attesa di qualcun altro" e ripeterla qui
     * direbbe il contrario di cio che la schermata ha appena detto sopra.
     *
     * Ordinate dalla piu ferma: chi trattiene il flusso da piu tempo sta in cima,
     * che e l'unica priorita utile a chi deve sollecitare.
     *
     * @return \Illuminate\Support\Collection<int, Release>
     */
    #[Computed]
    public function waitingReleases()
    {
        $user = auth()->user();

        return Release::query()
            ->inProgress()
            ->involving($user)
            ->whereDoesntHave('steps', fn (Builder $steps) => $steps
                ->where('assigned_user_id', $user->id)
                ->where('status', ReleaseStepStatus::Active))
            ->with([
                'project',
                'activeStep' => fn ($activeStep) => $activeStep->withActivationInstant()->with('assignedUser'),
            ])
            ->get()
            /*
             * Una release in corso ha sempre uno step attivo per invariante. Se un
             * dato incoerente entrasse, la riga sparisce invece di far fallire il
             * rendering di tutta la pagina — ma **lascia traccia**: su uno strumento
             * che esiste perche nulla resti fermo in silenzio, una release svanita
             * dalla schermata senza una riga di log sarebbe il difetto peggiore
             * possibile.
             */
            ->filter(function (Release $release): bool {
                if ($release->activeStep !== null) {
                    return true;
                }

                Log::warning('Release in corso senza step attivo, esclusa dalla vista operativa.', [
                    'release_id' => $release->id,
                ]);

                return false;
            })
            ->sortBy(fn (Release $release): string => implode('|', [
                $release->activeStep->activationInstant()->format('Y-m-d H:i:s'),
                $release->project->name,
                $release->label,
            ]))
            ->values();
    }

    /**
     * Progetti distinti su cui qualcosa attende: il secondo numero del contatore.
     */
    #[Computed]
    public function awaitingProjectCount(): int
    {
        return $this->mySteps
            ->map(fn (ReleaseStep $step): string => $step->release->project_id)
            ->unique()
            ->count();
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('my-steps.heading') }}</flux:heading>

        {{-- `aria-live="polite"` sul contatore. Oggi la pagina non ha azioni e non
             si aggiorna da sola, quindi la regione non ha ancora nulla da
             annunciare: e il contratto dichiarato dal mockup per quando ne avra
             (aggiornamento periodico o chiusura di uno step senza ricarica), e va
             messa **prima**, perche una regione live che compare insieme al proprio
             contenuto non annuncia comunque niente. Resta nel DOM anche a zero. --}}
        <flux:text class="mt-1" aria-live="polite">
            {{ trans_choice('my-steps.counter', $this->mySteps->count(), [
                'count' => $this->mySteps->count(),
                'projects' => trans_choice('my-steps.counter_projects', $this->awaitingProjectCount, [
                    'count' => $this->awaitingProjectCount,
                ]),
            ]) }}
        </flux:text>
    </div>

    {{-- Il titolo della sezione esiste per la struttura dei livelli — un solo
         `h1`, le due sezioni come `h2` — ma non a schermo: il mockup apre con le
         card, e ripetere a parole cio che il contatore ha appena detto sarebbe
         rumore per chi vede. --}}
    <flux:heading level="2" class="sr-only">{{ __('my-steps.steps_section') }}</flux:heading>

    @if ($this->mySteps->isEmpty())
        <flux:card class="space-y-2 text-center">
            <flux:icon name="check-circle" class="mx-auto size-8 text-zinc-400 dark:text-zinc-500" />

            <flux:heading size="lg" level="3">{{ __('my-steps.empty_heading') }}</flux:heading>

            {{-- Dice **quando** ne comparira uno: uno stato vuoto che dice solo
                 "non c'e niente" lascia senza sapere se sia normale. --}}
            <flux:text class="text-sm">{{ __('my-steps.empty_explained') }}</flux:text>
        </flux:card>
    @else
        <div class="space-y-3">
            @foreach ($this->mySteps as $step)
                {{--
                    La card **intera** e il comando, non solo il bottone: sotto
                    1024 px il bersaglio deve essere di almeno 44x44 px, e un
                    blocco cliccabile lo e sempre. `block` e non `flex` per non
                    introdurre soglie orizzontali a 375 px.

                    Nascondere o mostrare questo collegamento non e
                    autorizzazione: la Policy dello step decide lato server, e la
                    schermata di destinazione la applica al montaggio e su ogni
                    azione.
                --}}
                <a href="{{ route('releases.step', $step) }}" wire:key="step-{{ $step->id }}"
                   class="block rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-500 dark:focus-visible:outline-zinc-200">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <flux:text class="text-xs font-medium">
                            {{ $step->release->project->name }} · {{ $step->release->label }}
                        </flux:text>

                        {{-- Stato reso con **icona e parola**: chi non distingue i
                             colori non deve dedurre nulla dalla tinta. --}}
                        <flux:badge size="sm" icon="play-circle">{{ __('my-steps.status_your_turn') }}</flux:badge>
                    </div>

                    <flux:heading size="lg" level="3" class="mt-1">{{ $step->name }}</flux:heading>

                    <flux:text class="mt-1 text-sm">
                        {{ __('my-steps.step_position', [
                            'position' => $step->position,
                            'total' => $step->release->steps_count,
                        ]) }}
                        ·
                        {{-- `role_name` congelato, mai `role()->name`: rinominare
                             un ruolo non riscrive un rilascio gia in corso. --}}
                        {{ __('my-steps.step_as_role', ['role' => $step->role_name]) }}
                        ·
                        {{ __('my-steps.step_open_since', [
                            'duration' => $step->activationInstant()->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
                        ]) }}
                    </flux:text>

                    {{-- Un solo comando primario per card, a piena larghezza a 375
                         px. E `span` e non `button`: il bersaglio e la card, e un
                         comando dentro un comando romperebbe la navigazione da
                         tastiera. --}}
                    <span class="mt-3 flex min-h-11 w-full items-center justify-center gap-1.5 rounded-lg bg-zinc-800 px-4 text-sm font-medium text-white dark:bg-white dark:text-zinc-800">
                        <flux:icon name="pencil-square" variant="mini" class="size-4 shrink-0" />
                        {{ __('my-steps.step_open_action') }}
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($this->waitingReleases->isNotEmpty())
        {{-- Mitigazione del rischio accettato n.1 del PRD, non un abbellimento:
             senza notifiche, questo blocco e l'unico posto in cui si vede che un
             rilascio e fermo e su chi. --}}
        <div class="mt-8">
            <flux:heading size="lg" level="2">{{ __('my-steps.waiting_section') }}</flux:heading>

            <flux:text class="mt-1 text-sm">{{ __('my-steps.waiting_no_notifications') }}</flux:text>

            <div class="mt-3 space-y-2">
                @foreach ($this->waitingReleases as $release)
                    <flux:card wire:key="waiting-{{ $release->id }}">
                        <flux:text class="text-sm font-medium">
                            {{ $release->project->name }} · {{ $release->label }}
                        </flux:text>

                        <flux:text class="mt-0.5 inline-flex items-start gap-1.5 text-sm">
                            <flux:icon name="clock" variant="mini" class="mt-0.5 size-4 shrink-0" />
                            {{ __('my-steps.waiting_row', [
                                'name' => $release->activeStep->assignedUser->name,
                                'step' => $release->activeStep->name,
                                'duration' => $release->activeStep->activationInstant()->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
                            ]) }}
                        </flux:text>
                    </flux:card>
                @endforeach
            </div>
        </div>
    @endif
</div>
