<?php

use App\Enums\ReleaseEventAction;
use App\Models\Release;
use App\Models\ReleaseEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Registro delle transizioni di una release: cosa e successo, per mano di chi e
 * quando.
 *
 * Schermata di **sola lettura**, e qui non e una scelta di prodotto ma la sostanza
 * della cosa: un registro che si potesse modificare non sarebbe una prova. Il
 * rifiuto e presidiato due volte — `ReleaseEventPolicy` nega `update` e `delete` a
 * chiunque, amministratori inclusi, e `ReleaseEvent` solleva
 * `ReleaseEventIsAppendOnly` su ogni scrittura che passi da un modello.
 *
 * Alcune righe non sono per tutti: i **tentativi non autorizzati** nominano una
 * persona e cosa ha provato a fare, e restano ai soli amministratori. Il filtro vive
 * in query (`ReleaseEvent::visibleTo`) e non in memoria, perche il numero delle
 * righe nascoste e a sua volta informazione — e la pagina di chi non le vede non ne
 * segnala l'esistenza in alcun modo, nemmeno con un conteggio.
 *
 * Nessuna paginazione: le voci sono proporzionali alla lunghezza della catena, cioe
 * dell'ordine della decina per rilascio. Non e la tabella che cresce senza limiti.
 */
new class extends Component
{
    /** Release risolta dal binding di rotta. */
    public Release $release;

    public function mount(): void
    {
        // Secondo livello dopo il middleware, come su tutte le altre schermate.
        Gate::authorize('viewAny', ReleaseEvent::class);
    }

    /**
     * Le voci del registro che chi guarda puo vedere, in ordine cronologico.
     *
     * Una sola lettura, con attore e step in eager loading: la relazione verso lo
     * step e **nullable** — l'avvio non ne ha — e una relazione nullable caricata
     * pigramente e il modo piu silenzioso di reintrodurre una query per riga.
     *
     * @return Collection<int, ReleaseEvent>
     */
    #[Computed]
    public function entries(): Collection
    {
        return ReleaseEvent::query()
            ->forRelease($this->release)
            ->visibleTo(auth()->user())
            ->chronological()
            ->with(['user', 'releaseStep'])
            ->get();
    }

    /**
     * Icona per azione.
     *
     * Lo stato non e mai reso dal solo colore: ogni voce porta **icona e parola**,
     * e la parola arriva da `ReleaseEventAction::label()`, dove vive il vocabolario
     * che finisce anche in colonna.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function icons(): array
    {
        return [
            ReleaseEventAction::ReleaseStarted->value => 'rocket-launch',
            ReleaseEventAction::StepCompleted->value => 'check-circle',
            ReleaseEventAction::StepActivated->value => 'play-circle',
            ReleaseEventAction::ReleaseCompleted->value => 'flag',
            ReleaseEventAction::UnauthorizedAttempt->value => 'shield-exclamation',
        ];
    }

    /**
     * Frase di dettaglio di una voce, costruita dal suo payload.
     *
     * Il payload e **nullable** e le sue chiavi variano per azione: si legge con
     * `??` e mai come se fosse garantito. Una riga scritta da una versione
     * precedente dello strumento non ha le chiavi aggiunte dopo, e il registro deve
     * poterla mostrare comunque — e in sola aggiunta, quindi nessuno potra tornare
     * indietro a completarla.
     */
    public function detailOf(ReleaseEvent $event): ?string
    {
        $payload = $event->payload ?? [];

        return match ($event->action) {
            ReleaseEventAction::ReleaseStarted => isset($payload['template'])
                ? __('releases.log_detail_started', [
                    'template' => $payload['template'],
                    'steps' => $payload['steps'] ?? '?',
                ])
                : null,

            ReleaseEventAction::StepCompleted => isset($payload['fields_filled'])
                ? trans_choice('releases.log_detail_step_completed', (int) $payload['fields_filled'], [
                    'count' => (int) $payload['fields_filled'],
                ])
                : null,

            ReleaseEventAction::StepActivated => isset($payload['responsible'])
                ? __('releases.log_detail_step_activated', ['name' => $payload['responsible']])
                : null,

            ReleaseEventAction::ReleaseCompleted => isset($payload['label'])
                ? __('releases.log_detail_release_completed', ['label' => $payload['label']])
                : null,

            ReleaseEventAction::UnauthorizedAttempt => __('releases.log_detail_unauthorized', [
                'ability' => __('releases.log_ability_'.($payload['ability'] ?? 'unknown')),
            ]),
        };
    }
};
?>

<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('releases.index')">
            {{ __('releases.index_heading') }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('releases.show', $release)">
            {{ $release->project->name }} · {{ $release->label }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('releases.log_breadcrumb') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('releases.log_heading') }}</flux:heading>

        <flux:text class="mt-1">
            {{ __('releases.log_intro', [
                'project' => $release->project->name,
                'label' => $release->label,
            ]) }}
        </flux:text>

        {{-- La sola aggiunta non e una rifinitura tecnica da tacere: e cio che
             distingue questa pagina da un racconto, e chi la consulta per ricostruire
             un rilascio contestato deve saperlo senza dover leggere il codice. --}}
        <flux:text class="mt-2 text-xs">{{ __('releases.log_append_only') }}</flux:text>
    </div>

    @if ($this->entries->isEmpty())
        <flux:card class="space-y-2 text-center">
            <flux:icon name="clock" class="mx-auto size-8 text-zinc-400 dark:text-zinc-500" />

            <flux:heading size="lg" level="2">{{ __('releases.log_empty_heading') }}</flux:heading>

            {{-- Non accade su una release avviata — l'avvio scrive sempre una riga —
                 ma una pagina che tacesse davanti a un registro vuoto lascerebbe
                 senza sapere se sia normale o un difetto. --}}
            <flux:text class="text-sm">{{ __('releases.log_empty_explained') }}</flux:text>
        </flux:card>
    @else
        {{-- Lista **ordinata**: l'ordine e l'informazione principale di questa
             pagina, non una scelta di impaginazione. Colonna singola a ogni
             larghezza — una cronologia non ha nulla da affiancare, quindi non ha
             bisogno della soglia dei 1024 px. --}}
        <ol class="space-y-3">
            @foreach ($this->entries as $event)
                @php($detail = $this->detailOf($event))

                <li wire:key="event-{{ $event->id }}">
                    <flux:card class="space-y-2">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <flux:heading size="lg" level="2"
                                          class="inline-flex items-center gap-2">
                                <flux:icon :name="$this->icons[$event->action->value]"
                                           variant="mini" class="size-4 shrink-0" />
                                {{ $event->action->label() }}
                            </flux:heading>

                            {{-- L'istante nel fuso dell'applicazione, con il valore
                                 macchina in UTC sull'attributo: la stessa riga serve
                                 a chi legge e a chi la estrae. --}}
                            <time datetime="{{ $event->created_at->toIso8601String() }}"
                                  class="text-sm text-zinc-500 dark:text-white/70">
                                {{ $event->created_at->format('d/m/Y H:i') }}
                            </time>
                        </div>

                        <flux:text class="text-sm">
                            {{ __('releases.log_actor', ['name' => $event->user->name]) }}
                        </flux:text>

                        {{-- Lo step interessato quando c'e. L'avvio non ne ha, e la
                             riga lo dice invece di lasciare uno spazio vuoto da
                             interpretare. --}}
                        <flux:text class="text-sm">
                            @if ($event->releaseStep)
                                {{ __('releases.log_on_step', [
                                    'position' => $event->releaseStep->position,
                                    'step' => $event->releaseStep->name,
                                ]) }}
                            @else
                                {{ __('releases.log_on_release') }}
                            @endif
                        </flux:text>

                        @if ($detail)
                            <flux:text class="text-sm">{{ $detail }}</flux:text>
                        @endif

                        @if ($event->action === ReleaseEventAction::UnauthorizedAttempt)
                            {{-- Chi la vede deve sapere che sta guardando qualcosa
                                 che gli altri membri non vedono: senza la nota,
                                 potrebbe citarla in una discussione dando per scontato
                                 che sia visibile a tutti. --}}
                            <flux:text class="text-xs">{{ __('releases.log_admin_only') }}</flux:text>
                        @endif
                    </flux:card>
                </li>
            @endforeach
        </ol>
    @endif

    <div class="mt-6 max-lg:*:min-h-11 max-lg:*:w-full">
        <flux:button :href="route('releases.show', $release)" variant="ghost"
                     size="sm" icon="arrow-left">
            {{ __('releases.log_back_to_release') }}
        </flux:button>
    </div>
</div>
