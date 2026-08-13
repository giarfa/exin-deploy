<?php

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\Release;
use App\Models\ReleaseStep;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dettaglio di una release: la catena congelata con lo stato di ogni step, chi ne
 * risponde e cosa e stato fornito.
 *
 * E la risposta strutturale alla domanda "dove siamo e chi stiamo aspettando"
 * (FR-014), e per questo la pagina **non chiede nulla** a chi la apre: nessuna
 * azione, nessun form, nessuna scrittura. L'unico comando presente porta altrove —
 * alla schermata di chiusura — e compare solo dove la Policy dello step lo consente.
 *
 * La lettura e aperta a **ogni membro autenticato**, anche a chi e estraneo alla
 * catena: lo strumento non invia notifiche (rischio accettato n.1 del PRD), quindi
 * chi deve sollecitare deve poter vedere su chi il flusso si e fermato e da quanto.
 * L'autorizzazione e applicata due volte — middleware della rotta e Gate al
 * montaggio — come su tutte le altre schermate.
 *
 * Solo snapshot: `releases`, `release_steps`, `release_step_fields`. Nessun percorso
 * risale a `step_definitions` o `field_definitions`, come impone la regola portante
 * del progetto. Il nome del template e citato come **provenienza** e non come
 * definizione, e la nota accanto lo dichiara.
 */
new class extends Component
{
    /** Release risolta dal binding di rotta. */
    public Release $release;

    public function mount(): void
    {
        // Secondo livello dopo il middleware: le altre schermate fanno lo stesso, e
        // qui costa un controllo su un dato gia in memoria.
        Gate::authorize('view', $this->release);

        /*
         * Una sola lettura di dominio, tutto cio che la pagina mostra.
         *
         * `withActivationInstant()` **ridefinisce la select** (`select('{tabella}.*')`),
         * quindi va applicato prima di qualsiasi altra aggiunta alla select:
         * un `withCount` messo prima verrebbe cancellato senza avviso.
         */
        $this->release->load([
            'project',
            'workflowTemplate',
            'startedBy',
            'steps' => fn ($steps) => $steps
                ->withActivationInstant()
                ->with(['fields', 'assignedUser', 'completedBy']),
        ]);

        /*
         * Relazione inversa popolata a mano, e non e una rifinitura:
         * `ReleaseStep::activationInstant()` ripiega su `release->started_at` quando
         * lo step attivo e il primo della catena — cioe su ogni release appena
         * avviata — e senza questo il primo step risalirebbe alla release con una
         * query propria. `Release::activeStep()` ottiene lo stesso con `chaperone()`,
         * che sulle relazioni `HasMany` caricate da `load()` non e disponibile.
         */
        $this->release->steps->each(
            fn (ReleaseStep $step) => $step->setRelation('release', $this->release)
        );
    }

    /**
     * Catena congelata, nell'ordine deciso all'avvio.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ReleaseStep>
     */
    #[Computed]
    public function steps()
    {
        return $this->release->steps;
    }

    /**
     * Step su cui la release e ferma; `null` quando e conclusa.
     *
     * Letto dalla catena gia in memoria e non da `Release::activeStep()`: quella
     * relazione costerebbe una seconda query per un dato che questa pagina ha
     * interamente caricato.
     */
    #[Computed]
    public function activeStep(): ?ReleaseStep
    {
        return $this->steps->firstWhere('status', ReleaseStepStatus::Active);
    }

    /**
     * Step gia chiusi: il numeratore di "1 di 5" nel riquadro dei dati.
     */
    #[Computed]
    public function completedCount(): int
    {
        return $this->steps->where('status', ReleaseStepStatus::Completed)->count();
    }
};
?>

<div>
    {{--
        Briciole di navigazione in **deroga dichiarata** al mockup, che apre con
        "Release": l'elenco delle release non esiste ancora (US-009) e la pagina dei
        progetti e riservata agli amministratori, quindi entrambe le voci sarebbero
        un vicolo cieco o un 403 per un membro. La prima voce porta quindi a "i miei
        step", che ogni persona autenticata puo aprire. US-009 la sostituira con
        l'elenco e riportera il mockup alla lettera.
    --}}
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('home')">
            {{ __('releases.detail_breadcrumb_my_steps') }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $release->project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $release->label }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6">
        <flux:heading size="xl" level="1">
            {{ $release->project->name }} · {{ $release->label }}
        </flux:heading>

        {{-- Regione live come in "i miei step": la riga cambia quando la catena
             avanza, e chi non vede lo schermo deve poterlo sentire. Sta nel DOM
             prima del proprio contenuto, perche una regione che compare insieme al
             testo non annuncia comunque nulla. --}}
        <flux:text class="mt-1" aria-live="polite">
            @if ($release->status === ReleaseStatus::Completed)
                {{ __('releases.detail_summary_completed', [
                    'date' => $release->completed_at?->format('d/m/Y H:i'),
                ]) }}
            @elseif ($this->activeStep)
                {{ __('releases.detail_summary_in_progress', [
                    'position' => $this->activeStep->position,
                    'total' => $this->steps->count(),
                    'name' => $this->activeStep->assignedUser->name,
                ]) }}
            @else
                {{ __('releases.detail_summary_without_active_step') }}
            @endif
        </flux:text>
    </div>

    {{-- Colonna singola sotto 1024 px, catena e riquadro affiancati sopra: e la
         soglia `lg:` unica dell'applicazione (vincolo permanente n.4 del README),
         la stessa della shell. --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <flux:heading size="lg" level="2" class="mb-3">
                {{ __('releases.detail_chain_heading') }}
            </flux:heading>

            {{-- Lista **ordinata**: l'ordine e parte dell'informazione, non una
                 scelta di impaginazione. --}}
            <ol class="space-y-3">
                @foreach ($this->steps as $step)
                    @php
                        $statusIcon = match ($step->status) {
                            ReleaseStepStatus::Completed => 'check-circle',
                            ReleaseStepStatus::Active => 'play-circle',
                            ReleaseStepStatus::Blocked => 'lock-closed',
                        };

                        /*
                         * Cosa sblocca uno step bloccato: la chiusura di quello che
                         * lo precede **nella catena**, letto dalla collezione
                         * ordinata e non da `position - 1`. Le posizioni dello
                         * snapshot nascono contigue e non si riordinano, ma leggerle
                         * cosi non dipende da quella garanzia.
                         */
                        $previousStep = $loop->index > 0 ? $this->steps->get($loop->index - 1) : null;
                    @endphp

                    <li wire:key="step-{{ $step->id }}">
                        <flux:card class="space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <flux:heading size="lg" level="3">
                                    {{ $step->position }}. {{ $step->name }}
                                </flux:heading>

                                {{-- Stato reso con **icona e parola**, mai dal solo
                                     colore: chi non distingue le tinte non deve
                                     dedurre nulla. --}}
                                <flux:badge size="sm" :icon="$statusIcon">
                                    {{ $step->status->label() }}
                                </flux:badge>
                            </div>

                            {{-- `role_name` congelato, mai `role()->name`: rinominare
                                 un ruolo non riscrive un rilascio gia avvenuto. --}}
                            <flux:text class="text-sm">
                                {{ __('releases.detail_step_owner', [
                                    'role' => $step->role_name,
                                    'name' => $step->assignedUser->name,
                                ]) }}
                            </flux:text>

                            @if ($step->status === ReleaseStepStatus::Completed)
                                <flux:text class="text-sm">
                                    {{ __('releases.detail_step_closed_at', [
                                        'name' => $step->completedBy?->name ?? $step->assignedUser->name,
                                        'date' => $step->completed_at?->format('d/m/Y H:i'),
                                    ]) }}
                                </flux:text>

                                <flux:separator variant="subtle" />

                                <flux:heading size="lg" level="4">
                                    {{ __('releases.step_values_heading') }}
                                </flux:heading>

                                <x-releases.step-values :fields="$step->fields" />
                            @elseif ($step->status === ReleaseStepStatus::Active)
                                <flux:text class="text-sm">
                                    {{ __('releases.detail_step_active_since', [
                                        'duration' => $step->activationInstant()->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
                                    ]) }}
                                    ·
                                    {{ trans_choice('releases.detail_step_required_fields', $step->fields->count(), [
                                        'count' => $step->fields->count(),
                                    ]) }}
                                </flux:text>

                                {{-- Nascondere il comando non e autorizzazione: la
                                     Policy decide lato server e la schermata di
                                     destinazione la riapplica al montaggio e su ogni
                                     azione. Mostrarlo a chi verrebbe rifiutato
                                     sarebbe pero cattiva interfaccia. --}}
                                @can('fill', $step)
                                    <div class="max-lg:*:min-h-11 max-lg:*:w-full">
                                        <flux:button :href="route('releases.step', $step)" variant="primary"
                                                     size="sm" icon="pencil-square">
                                            {{ __('releases.step_open_action') }}
                                        </flux:button>
                                    </div>

                                    <flux:text class="text-xs">
                                        {{ __('releases.detail_step_open_reserved') }}
                                    </flux:text>
                                @endcan
                            @else
                                {{-- Nessun campo e nessun valore su uno step
                                     bloccato: e un criterio di accettazione, non una
                                     scelta di stile. Cio che non e ancora stato
                                     chiesto non ha nulla da mostrare. --}}
                                <flux:text class="text-sm">
                                    @if ($loop->last)
                                        {{ __('releases.detail_step_unlocks_last') }}
                                    @elseif ($previousStep)
                                        {{ __('releases.detail_step_unlocks_after', [
                                            'position' => $previousStep->position,
                                        ]) }}
                                    @else
                                        {{ __('releases.step_blocked_waiting_unknown') }}
                                    @endif
                                </flux:text>
                            @endif
                        </flux:card>
                    </li>
                @endforeach
            </ol>
        </div>

        <div>
            <flux:heading size="lg" level="2" class="mb-3">
                {{ __('releases.detail_meta_heading') }}
            </flux:heading>

            <flux:card class="space-y-4">
                {{-- Lista di definizione e non tabella: a 375 px due colonne
                     obbligherebbero allo scorrimento orizzontale. --}}
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('releases.detail_meta_project') }}
                        </dt>
                        <dd class="text-sm text-zinc-500 dark:text-white/70">{{ $release->project->name }}</dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('releases.detail_meta_label') }}
                        </dt>
                        <dd class="text-sm text-zinc-500 dark:text-white/70">{{ $release->label }}</dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('releases.detail_meta_status') }}
                        </dt>
                        <dd class="mt-0.5 text-sm text-zinc-500 dark:text-white/70">
                            <span class="inline-flex items-center gap-1.5">
                                <flux:icon
                                    :name="$release->status === ReleaseStatus::Completed ? 'check-circle' : 'play-circle'"
                                    variant="mini" class="size-4 shrink-0"
                                />
                                {{ $release->status->label() }}
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('releases.detail_meta_template') }}
                        </dt>
                        <dd class="text-sm text-zinc-500 dark:text-white/70">
                            {{ $release->workflowTemplate->name }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('releases.detail_meta_started_by') }}
                        </dt>
                        <dd class="text-sm text-zinc-500 dark:text-white/70">{{ $release->startedBy->name }}</dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('releases.detail_meta_started_at') }}
                        </dt>
                        <dd class="text-sm text-zinc-500 dark:text-white/70">
                            {{ $release->started_at->format('d/m/Y H:i') }}
                        </dd>
                    </div>

                    @if ($release->completed_at)
                        <div>
                            <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                                {{ __('releases.detail_meta_completed_at') }}
                            </dt>
                            <dd class="text-sm text-zinc-500 dark:text-white/70">
                                {{ $release->completed_at->format('d/m/Y H:i') }}
                            </dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('releases.detail_meta_completed_steps') }}
                        </dt>
                        <dd class="text-sm text-zinc-500 dark:text-white/70">
                            {{ __('releases.detail_meta_completed_steps_value', [
                                'completed' => $this->completedCount,
                                'total' => $this->steps->count(),
                            ]) }}
                        </dd>
                    </div>
                </dl>

                {{-- La nota toglie l'ambiguita del nome del template: quello mostrato
                     e il nome **attuale**, ma la catena, l'ordine e i campi arrivano
                     tutti dallo snapshot congelato all'avvio. --}}
                <flux:text class="text-xs">
                    {{ __('releases.detail_template_frozen', ['template' => $release->workflowTemplate->name]) }}
                </flux:text>
            </flux:card>
        </div>
    </div>
</div>
