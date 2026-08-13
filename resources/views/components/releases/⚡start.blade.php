<?php

use App\Actions\Releases\StartRelease;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\InactiveProjectCannotStartRelease;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\ProjectWithoutUsableTemplate;
use App\Exceptions\RolesWithoutResponsible;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Avvio di una release su un progetto.
 *
 * Deliberatamente minima: qui si indica un'etichetta e si ottiene la conferma
 * visiva che lo snapshot esiste. Da questa conferma si passa al dettaglio della
 * release, che mostra la catena con lo stato di ogni step e le informazioni
 * fornite; l'elenco con i filtri e di US-009.
 *
 * Le precondizioni sono mostrate **prima** del tentativo, e quando qualcosa manca
 * il comando e disabilitato con il motivo accanto: portare a una pagina che
 * rifiuta sarebbe dare la stessa informazione nel momento peggiore.
 *
 * Il rifiuto dell'Action e comunque catturato, perche fra il controllo e la
 * scrittura lo stato puo cambiare: stesso trattamento adottato da `setAsDefault()`
 * dopo la revisione di US-003 — un rifiuto e un messaggio, mai un 500.
 */
new class extends Component
{
    /** Progetto risolto dal binding di rotta. */
    public Project $project;

    public string $label = '';

    /** Release avviata in questa sessione di pagina; `null` prima dell'avvio. */
    public ?string $startedId = null;

    /** Etichetta della release avviata, tenuta qui per non rileggerla. */
    public ?string $startedLabel = null;

    /** Motivo del rifiuto dell'ultimo tentativo di avvio. */
    public ?string $operationError = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => [
                'required', 'string', 'max:255',
                // L'unicita e verificata anche qui e non solo dallo schema: un
                // messaggio di validazione dice cosa correggere, una violazione di
                // vincolo direbbe soltanto che qualcosa e andato storto.
                Rule::unique('releases', 'label')->where('project_id', $this->project->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'label' => __('releases.label'),
        ];
    }

    /**
     * Ruoli previsti dal processo che sul progetto non hanno un responsabile.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Role>
     */
    #[Computed]
    public function uncoveredRoles()
    {
        return $this->project->uncoveredRoles();
    }

    /**
     * Responsabili risolti ma disattivati: lo step verrebbe assegnato a chi non
     * accede piu, e resterebbe fermo.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function inactiveResponsibles()
    {
        return $this->project->inactiveResponsibles();
    }

    /**
     * Motivo per cui il progetto non e avviabile, `null` quando lo e.
     *
     * La regola vive sul modello: la stessa decisione serve all'elenco progetti,
     * che disabilita il comando di avvio con il motivo accanto, e due copie
     * divergerebbero.
     */
    #[Computed]
    public function blockingReason(): ?string
    {
        return $this->project->startBlocker();
    }

    /**
     * Catena congelata della release appena avviata.
     *
     * Eager loading obbligatorio: senza, il pannello di conferma farebbe una
     * query per step per mostrarne il responsabile.
     *
     * La release viene caricata insieme perche `ReleaseStepPolicy` la legge per
     * decidere `fill` — il comando di apertura dello step attivo. Senza,
     * `loadMissing('release')` dentro la Policy la caricherebbe una riga per volta.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ReleaseStep>
     */
    #[Computed]
    public function startedChain()
    {
        return ReleaseStep::query()
            ->where('release_id', $this->startedId)
            ->with(['assignedUser:id,name', 'release:id,status'])
            ->ordered()
            ->get();
    }

    public function mount(): void
    {
        // Il processo serve gia al primo render, per il riepilogo delle
        // precondizioni: caricarlo qui evita che ogni lettura lo ricarichi.
        $this->project->load([
            'workflowTemplate.stepDefinitions.role',
            'assignments.user',
        ]);
    }

    public function start(StartRelease $startRelease): void
    {
        Gate::authorize('create', [Release::class, $this->project]);

        $validated = $this->validate();

        /*
         * Il riepilogo mostrato sopra legge lo stato di un istante prima: fra
         * quella lettura e la scrittura il progetto puo essere stato disattivato,
         * il template svuotato o un responsabile rimosso. L'Action decide sul dato
         * fresco e rifiuta; senza queste catture il rifiuto diventerebbe un 500.
         *
         * Il messaggio viene ricavato dall'eccezione e non ricalcolato dallo stato
         * corrente: ricalcolarlo direbbe cosa manca **adesso**, e se nel frattempo
         * qualcun altro avesse sistemato la causa il messaggio finirebbe per
         * indicare il problema sbagliato — o nessuno.
         */
        try {
            $release = $startRelease->handle($this->project, $validated['label'], auth()->user());
        } catch (InactiveProjectCannotStartRelease) {
            $this->refuse(__('releases.blocked_inactive_project'));

            return;
        } catch (ProjectWithoutUsableTemplate $refused) {
            $this->refuse(__($refused->reasonKey));

            return;
        } catch (RolesWithoutResponsible $refused) {
            $this->refuse(trans_choice('releases.blocked_uncovered_roles', count($refused->roleNames), [
                'roles' => implode(', ', $refused->roleNames),
            ]));

            return;
        } catch (InactiveResponsibleOnProject $refused) {
            $this->refuse(trans_choice('releases.blocked_inactive_responsibles', count($refused->memberNames), [
                'members' => implode(', ', $refused->memberNames),
            ]));

            return;
        } catch (UniqueConstraintViolationException) {
            // Doppio invio: la validazione ha letto un istante prima, lo schema
            // decide. Il rifiuto torna come messaggio sul campo, dove si corregge.
            $this->addError('label', __('releases.duplicate_label', ['label' => $validated['label']]));

            return;
        }

        $this->operationError = null;
        $this->startedId = $release->id;
        $this->startedLabel = $release->label;
        $this->label = '';
        $this->resetValidation();
        unset($this->startedChain);
    }

    /**
     * Registra il rifiuto e riallinea il riepilogo delle precondizioni.
     */
    private function refuse(string $message): void
    {
        $this->operationError = $message;

        // Le precondizioni mostrate erano di un istante prima: dopo un rifiuto
        // vanno rilette, altrimenti la schermata continuerebbe a dire che tutto
        // e a posto sotto un messaggio che dice il contrario.
        $this->project->refresh();
        unset($this->uncoveredRoles, $this->inactiveResponsibles, $this->blockingReason);
    }

    /**
     * Torna al modulo di avvio dopo una release avviata con successo.
     */
    public function startAnother(): void
    {
        // Anche qui, e non solo su `start()`: le azioni Livewire non ripassano dal
        // middleware della rotta, e questo metodo e invocabile dal client. Chi ha
        // perso l'autorizzazione mentre la pagina era aperta non deve poter
        // rileggere lo stato del progetto.
        Gate::authorize('create', [Release::class, $this->project]);

        $this->startedId = null;
        $this->startedLabel = null;
        $this->operationError = null;

        // `refresh()` ricarica anche le relazioni gia caricate: un `load()` qui
        // rifarebbe le stesse query una seconda volta.
        $this->project->refresh();

        unset($this->uncoveredRoles, $this->inactiveResponsibles, $this->blockingReason, $this->startedChain);
    }
};
?>

<div>
    <flux:button :href="route('projects.index')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('releases.back_to_projects') }}
    </flux:button>

    <div class="mb-6">
        <flux:heading size="xl" level="1">
            {{ __('releases.heading', ['project' => $project->name]) }}
        </flux:heading>
        <flux:text class="mt-1">{{ __('releases.description') }}</flux:text>
    </div>

    @if ($startedId)
        <flux:callout variant="success" icon="check-circle" class="mb-6" aria-live="polite">
            {{ __('releases.started_heading', ['label' => $startedLabel]) }}

            <div class="mt-1">{{ __('releases.started_explained') }}</div>
        </flux:callout>

        <flux:card class="mb-6 space-y-4">
            <flux:heading size="lg" level="2">{{ __('releases.chain_heading') }}</flux:heading>

            {{-- Lista ordinata e non tabella: a 375 px cinque colonne
                 obbligherebbero allo scorrimento orizzontale. --}}
            <ol class="space-y-4">
                @foreach ($this->startedChain as $step)
                    <li class="border-s-2 border-zinc-200 ps-4 dark:border-zinc-700">
                        <flux:text class="text-xs">
                            {{ __('releases.chain_position', ['position' => $step->position]) }}
                        </flux:text>

                        <flux:heading size="lg" level="3" class="mt-0.5">{{ $step->name }}</flux:heading>

                        <flux:text class="mt-1 text-sm">
                            {{ $step->role_name }} — {{ __('releases.responsible') }}:
                            {{ $step->assignedUser->name }}
                        </flux:text>

                        {{-- Stato reso con icona e parola, mai dal solo colore. --}}
                        <span class="mt-1 inline-flex items-center gap-1.5 text-sm">
                            <flux:icon
                                :name="$step->status === ReleaseStepStatus::Active ? 'play-circle' : 'lock-closed'"
                                variant="mini"
                                class="size-4 shrink-0"
                            />
                            {{ $step->status->label() }}
                        </span>

                        {{--
                            Scorciatoia verso la schermata di chiusura per chi ha
                            appena avviato ed e anche il responsabile del primo step:
                            la navigazione ordinaria passa da "i miei step" (US-007) e
                            dal dettaglio della release (US-008), raggiungibile qui
                            sotto.

                            Il comando compare solo quando la Policy lo consente.
                            Nasconderlo non e autorizzazione — quella vive nel
                            componente di destinazione — ma mostrarlo a chi verrebbe
                            rifiutato e cattiva interfaccia.
                        --}}
                        @can('fill', $step)
                            <div class="mt-2">
                                <flux:button :href="route('releases.step', $step)" size="sm" variant="primary"
                                             icon="pencil-square">
                                    {{ __('releases.step_open_action') }}
                                </flux:button>
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ol>

            {{-- Il dettaglio della release e la destinazione naturale dopo l'avvio:
                 la stessa catena, ma con lo stato che avanza. Impilati a piena
                 larghezza sotto la soglia, in linea sopra. --}}
            <div class="flex flex-col gap-3 max-lg:*:min-h-11 max-lg:*:w-full lg:flex-row lg:items-center">
                <flux:button :href="route('releases.show', $startedId)" variant="outline" size="sm"
                             icon="queue-list">
                    {{ __('releases.started_open_detail') }}
                </flux:button>

                <flux:button wire:click="startAnother" variant="ghost" size="sm">
                    {{ __('releases.start_another') }}
                </flux:button>
            </div>
        </flux:card>
    @else
        @if ($operationError)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" aria-live="polite">
                {{ $operationError }}
            </flux:callout>
        @endif

        <flux:card class="mb-6 space-y-3">
            <flux:heading size="lg" level="2">
                {{ $this->blockingReason ? __('releases.blocked_heading') : __('releases.preconditions_heading') }}
            </flux:heading>

            @if ($this->blockingReason)
                <flux:text class="inline-flex items-start gap-1.5 text-sm">
                    <flux:icon name="exclamation-triangle" variant="mini" class="mt-0.5 size-4 shrink-0" />
                    {{ $this->blockingReason }}
                </flux:text>

                @if ($this->uncoveredRoles->isNotEmpty() || $this->inactiveResponsibles->isNotEmpty())
                    <div>
                        <flux:button :href="route('projects.assignments', $project)" size="sm" variant="ghost">
                            {{ __('releases.blocked_hint_assignments') }}
                        </flux:button>
                    </div>
                @endif
            @else
                <flux:text class="inline-flex items-center gap-1.5 text-sm">
                    <flux:icon name="queue-list" variant="mini" class="size-4 shrink-0" />
                    {{ __('releases.precondition_template', ['template' => $project->workflowTemplate->name]) }}
                </flux:text>

                <flux:text class="inline-flex items-center gap-1.5 text-sm">
                    <flux:icon name="bars-3-bottom-left" variant="mini" class="size-4 shrink-0" />
                    {{ trans_choice('releases.precondition_steps', $project->workflowTemplate->stepDefinitions->count(), [
                        'count' => $project->workflowTemplate->stepDefinitions->count(),
                    ]) }}
                </flux:text>

                <flux:text class="inline-flex items-center gap-1.5 text-sm">
                    <flux:icon name="check-circle" variant="mini" class="size-4 shrink-0" />
                    {{ __('releases.precondition_roles_ok') }}
                </flux:text>
            @endif
        </flux:card>

        <flux:card class="space-y-5">
            <form wire:submit="start" class="space-y-5">
                <flux:input wire:model="label" :label="__('releases.label')"
                            :description="__('releases.label_help')"
                            :disabled="(bool) $this->blockingReason" required />

                <flux:button type="submit" variant="primary" icon="rocket-launch"
                             :disabled="(bool) $this->blockingReason">
                    {{ __('releases.start_action') }}
                </flux:button>
            </form>
        </flux:card>
    @endif
</div>
