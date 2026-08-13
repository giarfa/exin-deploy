<?php

use App\Enums\ReleaseStatus;
use App\Models\Project;
use App\Models\Release;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Elenco delle release, in corso e concluse, con i filtri per stato e per progetto.
 *
 * Schermata di **sola lettura** come il dettaglio: nessuna azione, nessun form.
 * Risponde alla domanda d'insieme — quali rilasci sono aperti, su chi si sono
 * fermati, cosa e stato consegnato e quando — mentre il dettaglio risponde su un
 * rilascio solo. E aperta a ogni membro autenticato (`ReleasePolicy::viewAny`).
 *
 * Le due sezioni hanno **colonne diverse** e non e una svista: la prima risponde a
 * "chi stiamo aspettando", la seconda a "chi ha consegnato e quando". Uniformarle
 * lascerebbe meta tabella vuota in entrambe.
 *
 * Solo snapshot: `releases`, `release_steps` e le anagrafiche che nominano. Nessun
 * percorso risale a `step_definitions`, `field_definitions` o
 * `project_role_assignments`, come impone la regola portante del progetto.
 */
new class extends Component
{
    /** Valore che non filtra nulla, distinto dai casi di `ReleaseStatus`. */
    public const ALL = 'tutte';

    /**
     * Stato mostrato: `tutte`, oppure uno dei valori di `ReleaseStatus`.
     *
     * **Tre valori e non due.** Il mockup mostra entrambe le sezioni con "In corso"
     * premuto, cioe un filtro che nasconde l'altra e non un interruttore fra due
     * schermate: senza il terzo valore non esisterebbe il ritorno alla vista
     * d'insieme.
     *
     * Vive nell'indirizzo e non nel solo stato del componente: un elenco filtrato
     * deve essere condivisibile e ricaricabile — e la vista che si incolla in chat
     * per dire "guarda qui".
     */
    #[Url(as: 'stato', except: self::ALL)]
    public string $statusFilter = self::ALL;

    /** Progetto mostrato; stringa vuota significa "tutti i progetti". */
    #[Url(as: 'progetto', except: '')]
    public string $projectFilter = '';

    public function mount(): void
    {
        // Secondo livello dopo il middleware della rotta, come su tutte le altre
        // schermate: le azioni Livewire non ripassano da li.
        Gate::authorize('viewAny', Release::class);
    }

    /**
     * Stato richiesto, ricondotto ai soli valori previsti.
     *
     * I filtri arrivano dalla barra dell'indirizzo, quindi da un input non fidato:
     * `?stato=qualsiasi-cosa` deve mostrare tutto e non far fallire il cast a enum
     * dentro la query. Il ripiego e silenzioso di proposito — un indirizzo
     * incollato male non e un errore dell'utente da annunciare.
     */
    #[Computed]
    public function selectedStatus(): ?ReleaseStatus
    {
        return ReleaseStatus::tryFrom($this->statusFilter);
    }

    /**
     * Filtri di stato offerti, nell'ordine in cui compaiono.
     *
     * Un solo elenco invece di tre bottoni quasi identici: il vocabolario degli
     * stati resta quello di `ReleaseStatus::label()`, dove sta gia (vedi la nota in
     * `lang/it/releases.php`).
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function statusFilters(): array
    {
        return [
            ['value' => self::ALL, 'label' => __('releases.index_filter_all')],
            ...array_map(fn (ReleaseStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ], ReleaseStatus::cases()),
        ];
    }

    /** La sezione delle release in corso e visibile. */
    #[Computed]
    public function showsInProgress(): bool
    {
        return $this->selectedStatus !== ReleaseStatus::Completed;
    }

    /** La sezione dello storico e visibile. */
    #[Computed]
    public function showsCompleted(): bool
    {
        return $this->selectedStatus !== ReleaseStatus::InProgress;
    }

    /**
     * Intestazioni della sezione in corso, indicizzate per colonna.
     *
     * Lo stesso array genera l'intestazione della tabella **e** l'etichetta che
     * ricompare accanto al valore quando la riga diventa una card sotto 1024 px:
     * scritte in due posti, le due divergerebbero alla prima rinomina.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function inProgressColumns(): array
    {
        return [
            'project' => __('releases.index_column_project'),
            'label' => __('releases.index_column_label'),
            'status' => __('releases.index_column_status'),
            'current_step' => __('releases.index_column_current_step'),
            'waiting_on' => __('releases.index_column_waiting_on'),
            'started_at' => __('releases.index_column_started_at'),
        ];
    }

    /**
     * Intestazioni dello storico, indicizzate per colonna.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function completedColumns(): array
    {
        return [
            'project' => __('releases.index_column_project'),
            'label' => __('releases.index_column_label'),
            'status' => __('releases.index_column_status'),
            'delivered_by' => __('releases.index_column_delivered_by'),
            'completed_at' => __('releases.index_column_completed_at'),
            'duration' => __('releases.index_column_duration'),
        ];
    }

    /**
     * Release in corso, dalla piu recente.
     *
     * "Recente" e qui l'istante di **avvio**: e l'unico che una release aperta
     * possiede.
     *
     * L'ordine delle aggiunte alla query non e libero: `withActivationInstant()`
     * ridefinisce la select dello step (vedi `.ai/rules/components-releases.md`),
     * quindi va applicato per primo dentro la relazione. Il `withCount` sta invece
     * sulla release, e da il denominatore di "step 2 di 5" senza contare la catena
     * riga per riga.
     *
     * Quando la sezione non e visibile la collezione e vuota **senza interrogare il
     * database**: filtrare per stato non deve costare la lettura che si e appena
     * chiesto di nascondere.
     *
     * @return Collection<int, Release>
     */
    #[Computed]
    public function inProgressReleases(): Collection
    {
        if (! $this->showsInProgress) {
            return new Collection;
        }

        return Release::query()
            ->inProgress()
            ->forProject($this->projectFilter)
            ->with([
                'project',
                'activeStep' => fn ($activeStep) => $activeStep->withActivationInstant()->with('assignedUser'),
            ])
            ->withCount('steps')
            ->orderByDesc('started_at')
            // Spareggio deterministico: due release avviate nello stesso istante —
            // che le factory e il seeder producono — altrimenti si scambierebbero di
            // posto fra due letture, e un test sull'ordine sarebbe instabile.
            ->orderBy('label')
            ->get();
    }

    /**
     * Release concluse, dalla piu recente.
     *
     * "Recente" e qui l'istante di **consegna** e non quello di avvio, ed e una
     * differenza che si vede: ordinare lo storico su `started_at` metterebbe in cima
     * un rilascio avviato a marzo e consegnato ieri, sotto uno avviato e consegnato
     * ad aprile.
     *
     * Nessun limite di data e nessuna paginazione: lo storico e consultabile a tempo
     * indeterminato per criterio di accettazione, e su un team interno cresce di
     * qualche riga a settimana. Il costo di lettura non dipende dal numero di righe
     * (vincolato da `ReleaseIndexQueryBudgetTest`); quando lo storico raggiungera
     * l'ordine delle centinaia questa sezione va paginata — e la stessa soglia oltre
     * la quale l'ordinamento di "i miei step" va spostato in SQL.
     *
     * @return Collection<int, Release>
     */
    #[Computed]
    public function completedReleases(): Collection
    {
        if (! $this->showsCompleted) {
            return new Collection;
        }

        return Release::query()
            ->completed()
            ->forProject($this->projectFilter)
            ->with(['project', 'completedBy'])
            ->orderByDesc('completed_at')
            ->orderBy('label')
            ->get();
    }

    /**
     * Progetti su cui esiste almeno una release.
     *
     * Non l'anagrafica intera: un progetto senza rilasci e un'opzione che filtra
     * verso il vuoto, cioe un modo per far sembrare rotta una schermata sana.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function selectableProjects(): Collection
    {
        return Project::query()
            ->whereHas('releases')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Numero di release in corso, con il filtro di progetto applicato. */
    #[Computed]
    public function inProgressCount(): int
    {
        return Release::query()->inProgress()->forProject($this->projectFilter)->count();
    }

    /** Numero di release concluse, con il filtro di progetto applicato. */
    #[Computed]
    public function completedCount(): int
    {
        return Release::query()->completed()->forProject($this->projectFilter)->count();
    }

    /**
     * Nome del progetto filtrato, per gli stati vuoti; `null` quando non si filtra.
     */
    #[Computed]
    public function selectedProjectName(): ?string
    {
        return $this->selectableProjects->firstWhere('id', $this->projectFilter)?->name;
    }

    /**
     * Responsabile che trattiene il flusso, e da quanto.
     *
     * Una release in corso ha sempre uno step attivo per invariante. Se un dato
     * incoerente entrasse, la riga resta a elenco con la propria etichetta invece di
     * sparire — su uno strumento che esiste perche nulla resti fermo in silenzio,
     * una release svanita dall'elenco sarebbe il difetto peggiore possibile — ma
     * **lascia traccia** nel log, come fa "i miei step".
     *
     * @return array{name: string|null, step: string|null, position: int|null, duration: string|null}
     */
    public function waitingOn(Release $release): array
    {
        if ($release->activeStep === null) {
            Log::warning('Release in corso senza step attivo, resa senza responsabile in attesa.', [
                'release_id' => $release->id,
            ]);

            return ['name' => null, 'step' => null, 'position' => null, 'duration' => null];
        }

        return [
            'name' => $release->activeStep->assignedUser->name,
            'step' => $release->activeStep->name,
            'position' => $release->activeStep->position,
            'duration' => $release->activeStep
                ->activationInstant()
                ->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
        ];
    }

    /**
     * Durata del rilascio: dall'avvio alla consegna.
     *
     * Due parti come nel mockup ("1 giorno, 6 ore"): la sola unita maggiore direbbe
     * "1 giorno" tanto per venticinque ore quanto per quarantasette.
     */
    public function deliveryDuration(Release $release): string
    {
        return $release->completed_at->diffForHumans(
            $release->started_at,
            syntax: CarbonInterface::DIFF_ABSOLUTE,
            parts: 2,
        );
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('releases.index_heading') }}</flux:heading>

        {{-- Regione live come sulle altre schermate operative: i numeri cambiano al
             variare del filtro di progetto, e chi non vede lo schermo deve poterlo
             sentire. Sta nel DOM prima del proprio contenuto, perche una regione che
             compare insieme al testo non annuncia comunque nulla. --}}
        <flux:text class="mt-1" aria-live="polite">
            {{ __('releases.index_counter', [
                'in_progress' => trans_choice('releases.index_counter_in_progress', $this->inProgressCount, [
                    'count' => $this->inProgressCount,
                ]),
                'completed' => trans_choice('releases.index_counter_completed', $this->completedCount, [
                    'count' => $this->completedCount,
                ]),
            ]) }}
        </flux:text>
    </div>

    {{-- Filtri: impilati a piena larghezza a 375 px, in linea da 640 px. --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <div class="flex flex-col gap-2 sm:flex-row">
            @foreach ($this->statusFilters as $filter)
                {{-- `aria-pressed` e non un gruppo di radio: sono comandi che
                     cambiano cio che si vede subito, non una scelta da confermare.
                     Lo stato premuto e annunciato, non dedotto dal colore. --}}
                <flux:button size="sm" wire:key="filtro-{{ $filter['value'] }}"
                             wire:click="$set('statusFilter', @js($filter['value']))"
                             :variant="$statusFilter === $filter['value'] ? 'primary' : 'ghost'"
                             :aria-pressed="$statusFilter === $filter['value'] ? 'true' : 'false'"
                             class="max-sm:w-full">
                    {{ $filter['label'] }}
                </flux:button>
            @endforeach
        </div>

        @if ($this->selectableProjects->isNotEmpty())
            <flux:select wire:model.live="projectFilter" size="sm"
                         :label="__('releases.index_filter_project')" class="sm:w-64">
                <flux:select.option value="">{{ __('releases.index_filter_project_all') }}</flux:select.option>

                @foreach ($this->selectableProjects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->showsInProgress)
        @php($columns = $this->inProgressColumns)

        <flux:heading size="lg" level="2" class="mb-3">
            {{ __('releases.index_section_in_progress') }}
        </flux:heading>

        @if ($this->inProgressReleases->isEmpty())
            <flux:card class="mb-8 space-y-2 text-center">
                <flux:icon name="check-circle" class="mx-auto size-8 text-zinc-400 dark:text-zinc-500" />

                <flux:heading size="lg" level="3">
                    {{ __('releases.index_empty_in_progress_heading') }}
                </flux:heading>

                {{-- Dice **quale filtro** produce il vuoto: "nessun risultato" senza
                     dire cosa cambiare lascia a indovinare. --}}
                <flux:text class="text-sm">
                    {{ $this->selectedProjectName
                        ? __('releases.index_empty_filtered', ['project' => $this->selectedProjectName])
                        : __('releases.index_empty_in_progress_explained') }}
                </flux:text>
            </flux:card>
        @else
            {{--
                Una sola tabella semantica, non due alberi DOM: sotto 1024 px le
                utility `max-lg:` la impilano in card — `thead` nascosto, righe a
                blocco, etichetta di colonna resa dal pseudo-elemento della cella —
                e sopra torna una tabella. Duplicare il contenuto per breakpoint lo
                farebbe leggere due volte a uno screen reader.

                Nessun contenitore con scorrimento orizzontale: il criterio di
                accettazione lo esclude a ogni larghezza, e un `overflow-x-auto`
                sarebbe il modo piu facile per farlo rientrare senza accorgersene.
            --}}
            <table class="mb-8 w-full border-collapse text-left text-sm max-lg:block">
                <caption class="sr-only">{{ __('releases.index_caption_in_progress') }}</caption>

                <thead class="max-lg:hidden">
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        @foreach ($columns as $heading)
                            <th scope="col"
                                class="px-3 py-2 text-xs font-semibold text-zinc-500 uppercase dark:text-white/70">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="max-lg:block max-lg:space-y-3">
                    @foreach ($this->inProgressReleases as $release)
                        @php($waiting = $this->waitingOn($release))

                        <tr wire:key="in-progress-{{ $release->id }}"
                            class="border-b border-zinc-200 last:border-b-0 dark:border-zinc-700 max-lg:block max-lg:space-y-1.5 max-lg:rounded-xl max-lg:border max-lg:bg-white max-lg:p-4 dark:max-lg:bg-zinc-900">
                            <x-releases.list-cell :label="$columns['project']" heading>
                                {{-- Sotto 1024 px il bersaglio deve essere di almeno
                                     44x44 px: il collegamento porta la propria altezza
                                     minima invece di affidarsi a quella della riga. --}}
                                <flux:link :href="route('releases.show', $release)"
                                           class="inline-flex items-center max-lg:min-h-11">
                                    {{ $release->project->name }}
                                </flux:link>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['label']">
                                <span>{{ $release->label }}</span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['status']">
                                {{-- Stato reso con **icona e parola**, mai dal solo
                                     colore: chi non distingue le tinte non deve
                                     dedurre nulla. --}}
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon name="play-circle" variant="mini" class="size-4 shrink-0" />
                                    {{ $release->status->label() }}
                                </span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['current_step']">
                                <span class="max-lg:text-right">
                                    @if ($waiting['step'])
                                        {{ __('releases.index_current_step', [
                                            'position' => $waiting['position'],
                                            'total' => $release->steps_count,
                                            'step' => $waiting['step'],
                                        ]) }}
                                    @else
                                        {{ __('releases.index_without_active_step') }}
                                    @endif
                                </span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['waiting_on']">
                                <span class="max-lg:text-right">
                                    @if ($waiting['name'])
                                        {{ __('releases.index_waiting_on', [
                                            'name' => $waiting['name'],
                                            'duration' => $waiting['duration'],
                                        ]) }}
                                    @else
                                        {{ __('releases.index_waiting_on_nobody') }}
                                    @endif
                                </span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['started_at']" class="whitespace-nowrap">
                                <span>{{ $release->started_at->format('d/m/Y') }}</span>
                            </x-releases.list-cell>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($this->showsCompleted)
        @php($columns = $this->completedColumns)

        <flux:heading size="lg" level="2" class="mb-3">
            {{ __('releases.index_section_completed') }}
        </flux:heading>

        @if ($this->completedReleases->isEmpty())
            <flux:card class="space-y-2 text-center">
                <flux:icon name="archive-box" class="mx-auto size-8 text-zinc-400 dark:text-zinc-500" />

                <flux:heading size="lg" level="3">
                    {{ __('releases.index_empty_completed_heading') }}
                </flux:heading>

                <flux:text class="text-sm">
                    {{ $this->selectedProjectName
                        ? __('releases.index_empty_filtered', ['project' => $this->selectedProjectName])
                        : __('releases.index_empty_completed_explained') }}
                </flux:text>
            </flux:card>
        @else
            <table class="w-full border-collapse text-left text-sm max-lg:block">
                <caption class="sr-only">{{ __('releases.index_caption_completed') }}</caption>

                <thead class="max-lg:hidden">
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        @foreach ($columns as $heading)
                            <th scope="col"
                                class="px-3 py-2 text-xs font-semibold text-zinc-500 uppercase dark:text-white/70">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="max-lg:block max-lg:space-y-3">
                    @foreach ($this->completedReleases as $release)
                        <tr wire:key="completed-{{ $release->id }}"
                            class="border-b border-zinc-200 last:border-b-0 dark:border-zinc-700 max-lg:block max-lg:space-y-1.5 max-lg:rounded-xl max-lg:border max-lg:bg-white max-lg:p-4 dark:max-lg:bg-zinc-900">
                            <x-releases.list-cell :label="$columns['project']" heading>
                                <flux:link :href="route('releases.show', $release)"
                                           class="inline-flex items-center max-lg:min-h-11">
                                    {{ $release->project->name }}
                                </flux:link>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['label']">
                                <span>{{ $release->label }}</span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['status']">
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon name="check-circle" variant="mini" class="size-4 shrink-0" />
                                    {{ $release->status->label() }}
                                </span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['delivered_by']">
                                {{-- `completedBy` puo mancare solo su un dato
                                     incoerente: la conclusione lo scrive nella stessa
                                     transazione che marca la release. --}}
                                <span class="max-lg:text-right">
                                    {{ $release->completedBy?->name ?? __('releases.index_delivered_by_unknown') }}
                                </span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['completed_at']" class="whitespace-nowrap">
                                <span>{{ $release->completed_at->format('d/m/Y H:i') }}</span>
                            </x-releases.list-cell>

                            <x-releases.list-cell :label="$columns['duration']">
                                <span class="max-lg:text-right">{{ $this->deliveryDuration($release) }}</span>
                            </x-releases.list-cell>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>
