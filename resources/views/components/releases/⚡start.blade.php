<?php

use App\Actions\Releases\StartRelease;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\InactiveProjectCannotStartRelease;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\ProjectWithoutUsableTemplate;
use App\Exceptions\RolesWithoutResponsible;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Avvio di una release su un progetto.
 *
 * Qui si indica un'etichetta, si conferma o si sostituisce il responsabile di
 * ciascun ruolo, e si ottiene la conferma visiva che lo snapshot esiste. Da questa
 * conferma si passa al dettaglio della release, che mostra la catena con lo stato
 * di ogni step e le informazioni fornite; l'elenco con i filtri e di US-009.
 *
 * Le precondizioni sono mostrate **prima** del tentativo, e quando qualcosa manca
 * il comando e disabilitato con il motivo accanto: portare a una pagina che
 * rifiuta sarebbe dare la stessa informazione nel momento peggiore.
 *
 * Da US-013 il riepilogo si calcola sulla mappatura **effettiva** — quella di
 * progetto con gli override di questa release sovrapposti — cosi che un ruolo
 * scoperto si risolva da qui, senza passare dalla pagina dei responsabili del
 * progetto. Il ricalcolo non duplica la regola: chiama gli **stessi** metodi di
 * `Project` su un clone con la mappatura effettiva al posto di quella persistita.
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

    /**
     * Persona scelta per ciascun ruolo del processo, indicizzata per
     * identificativo di ruolo. Stringa vuota significa "nessuno indicato", e vale
     * solo per un ruolo che non ha un responsabile sul progetto.
     *
     * E lo stato del modulo, non il carico passato alla Action: quello si ricava
     * da `overriddenAssignees`, che tiene le sole scelte **diverse** da quella
     * proposta. Passare anche le altre congelerebbe una lettura vecchia di un
     * istante invece di lasciare decidere l'Action sul dato fresco.
     *
     * @var array<string, string>
     */
    public array $overrides = [];

    /**
     * Valore da cui il modulo e partito per ciascun ruolo: il responsabile di
     * progetto al momento in cui il select e stato proposto.
     *
     * Non e ridondante rispetto alla mappatura corrente, ed e la differenza fra un
     * override e un residuo. Fra l'apertura del modulo e l'invio la mappatura puo
     * cambiare: se il confronto avvenisse col dato di **adesso**, un valore
     * preselezionato e mai toccato diventerebbe una sostituzione appena
     * l'assegnazione venisse rimossa da qualcun altro — e la release partirebbe
     * congelando una persona che nessuno ha piu indicato, invece di essere
     * rifiutata per ruolo scoperto.
     *
     * @var array<string, string>
     */
    public array $primedDefaults = [];

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
        $assignable = $this->assignableUsers->pluck('id')->all();

        $rules = [
            'label' => [
                'required', 'string', 'max:255',
                // L'unicita e verificata anche qui e non solo dallo schema: un
                // messaggio di validazione dice cosa correggere, una violazione di
                // vincolo direbbe soltanto che qualcosa e andato storto.
                Rule::unique('releases', 'label')->where('project_id', $this->project->id),
            ],
            'overrides' => ['array'],
            /*
             * La stringa vuota e un valore legittimo: significa "nessun override",
             * e il ruolo torna a dipendere dalla mappatura di progetto. Il vincolo
             * di appartenenza chiude il resto — dal modulo si scelgono solo persone
             * dell'insieme selezionabile, e un identificativo arrivato per altra via
             * non deve poter assegnare uno step a chi non e in elenco.
             */
            'overrides.*' => ['string', Rule::in(array_merge($assignable, [''])) ],
        ];

        /*
         * Un ruolo che resta senza responsabile nella mappatura effettiva non si
         * avvia: qui indicare una persona non e una preferenza, e la condizione per
         * procedere. La regola e per chiave e non sul carattere jolly, perche il
         * messaggio deve dire **quale** ruolo manca.
         */
        foreach ($this->effectiveProject->uncoveredRoles() as $role) {
            $rules['overrides.'.$role->id] = ['required', 'string', Rule::in($assignable)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'overrides.*.required' => __('releases.override_required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return $this->processRoles
            ->mapWithKeys(fn (Role $role): array => [
                'overrides.'.$role->id => __('releases.override_role_label', ['role' => $role->name]),
            ])
            ->put('label', __('releases.label'))
            ->all();
    }

    /**
     * Ruoli previsti dagli step del processo, una volta ciascuno.
     *
     * Letti dalle relazioni gia precaricate in `mount()`: a pagina servita questo
     * computed non interroga il database. `loadMissing` e la rete che le rimette in
     * memoria dopo un `refresh()`, dove il ricaricamento riguarda le sole relazioni
     * di primo livello.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    #[Computed]
    public function processRoles()
    {
        $this->preload();

        $template = $this->project->workflowTemplate;

        if ($template === null) {
            return collect();
        }

        return $template->stepDefinitions
            ->pluck('role')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Persone selezionabili: i membri attivi, piu quelli disattivati gia assegnati
     * su questo progetto — altrimenti la selezione in corso sparirebbe dall'elenco
     * proprio mentre la si sta sostituendo.
     *
     * Stessa forma del computed omonimo della pagina dei responsabili di progetto:
     * l'insieme selezionabile e lo stesso, e due definizioni divergerebbero.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    #[Computed]
    public function assignableUsers()
    {
        $assignedIds = $this->project->assignments->pluck('user_id')->all();

        return User::query()
            ->select(['id', 'name', 'is_active'])
            ->where('is_active', true)
            ->orWhereIn('id', $assignedIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Responsabile risolto dalla mappatura di progetto per ciascun ruolo,
     * indicizzato per ruolo. Serve a marcare il default nel select e a riconoscere
     * quali scelte sono davvero una sostituzione.
     *
     * @return \Illuminate\Support\Collection<string, string>
     */
    #[Computed]
    public function defaultAssignees()
    {
        $this->preload();

        return $this->project->assignments
            ->keyBy('role_id')
            ->map(fn (ProjectRoleAssignment $assignment): string => (string) $assignment->user_id);
    }

    /**
     * Le sole scelte che **sostituiscono** il valore proposto, indicizzate per
     * ruolo: e il carico passato alla Action.
     *
     * Una scelta uguale a quella proposta non e un override e non viene inviata.
     * La differenza non e cosmetica: per quei ruoli l'Action continua a risolvere
     * sul dato fresco, quindi il rifiuto post-invio resta quello di sempre invece
     * di congelare la lettura di un istante prima.
     *
     * @return \Illuminate\Support\Collection<string, string>
     */
    #[Computed]
    public function overriddenAssignees()
    {
        $assignable = $this->assignableUsers->pluck('id')->all();

        return collect($this->overrides)
            ->only($this->processRoles->pluck('id')->all())
            ->map(fn (mixed $userId): string => (string) $userId)
            ->filter(fn (string $userId, string $roleId): bool => $userId !== ''
                && $userId !== ($this->primedDefaults[$roleId] ?? '')
                // Un identificativo fuori dall'insieme selezionabile non vale come
                // override: la validazione lo rifiuta all'invio, e fino a quel
                // momento il riepilogo deve restare onesto sul blocco che resta.
                && in_array($userId, $assignable, true));
    }

    /**
     * Il progetto con la mappatura **effettiva** al posto di quella persistita.
     *
     * Le assegnazioni sostituite sono istanze `ProjectRoleAssignment` **non
     * persistite**, sovrapposte alla relazione di un **clone** del progetto. Due
     * cautele, non una: le istanze non vengono mai salvate — l'override e un
     * effetto one-shot sulla singola release — e il clone serve perche
     * `$this->project` deve continuare a descrivere lo stato persistito, che e
     * quello che la pagina dei responsabili mostrera dopo l'avvio.
     *
     * Cosi `uncoveredRoles()`, `inactiveResponsibles()` e `startBlocker()`
     * ricalcolano senza che una sola riga della loro regola venga riscritta qui.
     */
    #[Computed]
    public function effectiveProject(): Project
    {
        $this->preload();

        $byId = $this->assignableUsers->keyBy('id');

        $overridden = $this->overriddenAssignees
            ->map(function (string $userId, string $roleId) use ($byId): ProjectRoleAssignment {
                $assignment = new ProjectRoleAssignment([
                    'project_id' => $this->project->id,
                    'role_id' => $roleId,
                    'user_id' => $userId,
                ]);

                // La persona e gia in memoria: senza questa relazione
                // `inactiveResponsibles()` la ricaricherebbe una riga per volta.
                $assignment->setRelation('user', $byId->get($userId));

                return $assignment;
            });

        $effective = $this->project->assignments
            ->reject(fn (ProjectRoleAssignment $assignment): bool => $overridden->has($assignment->role_id))
            ->values()
            ->concat($overridden->values());

        $clone = clone $this->project;
        $clone->setRelation('assignments', $effective);

        return $clone;
    }

    /**
     * Ruoli previsti dal processo che restano senza responsabile nella mappatura
     * effettiva.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    #[Computed]
    public function uncoveredRoles()
    {
        return $this->effectiveProject->uncoveredRoles();
    }

    /**
     * Responsabili effettivi ma disattivati: lo step verrebbe assegnato a chi non
     * accede piu, e resterebbe fermo.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function inactiveResponsibles()
    {
        return $this->effectiveProject->inactiveResponsibles();
    }

    /**
     * Motivo per cui il progetto non e avviabile con le scelte correnti, `null`
     * quando lo e.
     *
     * La regola vive sul modello: la stessa decisione serve all'elenco progetti, e
     * due copie divergerebbero. Qui cambia solo il progetto su cui la si valuta.
     */
    #[Computed]
    public function blockingReason(): ?string
    {
        return $this->effectiveProject->startBlocker();
    }

    /**
     * Se il blocco residuo sia di quelli che un override puo risolvere.
     *
     * Espresso in positivo sulle due condizioni che nessuna scelta di persona
     * sistema: progetto disattivato, processo assente o inutilizzabile. Quando
     * entrambe sono a posto, qualunque motivo residuo riguarda i responsabili, cioe
     * esattamente cio che questo modulo permette di indicare.
     */
    #[Computed]
    public function overridable(): bool
    {
        return $this->project->is_active
            && (bool) $this->project->workflowTemplate?->isUsable();
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

        $this->primeOverrides();
    }

    /**
     * Rimette in memoria le relazioni che servono a leggere la mappatura.
     *
     * Non e un secondo eager loading: a pagina servita `mount()` le ha gia
     * caricate e `loadMissing` non interroga nulla. Serve dopo un `refresh()`, che
     * ricarica le sole relazioni di primo livello e lascerebbe gli step e le
     * persone da rileggere una riga per volta.
     */
    private function preload(): void
    {
        $this->project->loadMissing([
            'workflowTemplate.stepDefinitions.role',
            'assignments.user',
        ]);
    }

    /**
     * Porta ogni ruolo del processo al proprio responsabile di progetto, senza
     * toccare le scelte gia fatte.
     *
     * Riempie le sole chiavi mancanti e lascia cadere quelle dei ruoli che il
     * processo non prevede piu: dopo un rifiuto il progetto viene riletto, e un
     * select senza valore corrispondente mostrerebbe la prima voce dell'elenco
     * facendola passare per una scelta.
     */
    private function primeOverrides(): void
    {
        $defaults = $this->defaultAssignees;

        $selections = [];
        $primed = [];

        foreach ($this->processRoles as $role) {
            $default = (string) ($defaults[$role->id] ?? '');

            $selections[$role->id] = (string) ($this->overrides[$role->id] ?? $default);
            // Il valore di partenza gia registrato non si riscrive: e il riferimento
            // rispetto a cui una scelta conta come sostituzione, e aggiornarlo qui
            // trasformerebbe un preselezionato mai toccato in un override.
            $primed[$role->id] = (string) ($this->primedDefaults[$role->id] ?? $default);
        }

        $this->overrides = $selections;
        $this->primedDefaults = $primed;
    }

    public function start(StartRelease $startRelease): void
    {
        Gate::authorize('create', [Release::class, $this->project]);

        /*
         * Le chiavi sono vincolate ai ruoli del processo **prima** della
         * validazione, come fa `save()` sulla pagina dei responsabili: senza, un
         * ruolo estraneo al processo arriverebbe fino alla Action, che lo
         * scarterebbe in silenzio invece di dirlo qui.
         */
        $this->overrides = collect($this->overrides)
            ->only($this->processRoles->pluck('id')->all())
            ->map(fn (mixed $userId): string => (string) $userId)
            ->all();

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
            $release = $startRelease->handle(
                $this->project,
                $validated['label'],
                auth()->user(),
                $this->overriddenAssignees->all(),
            );
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
        $this->forgetMappingReads();

        // Anche l'insieme selezionabile e la marcatura del default sono di un
        // istante prima: le scelte restano, i ruoli nuovi partono dal proprio
        // responsabile di progetto.
        $this->primeOverrides();
    }

    /**
     * Dimentica tutto cio che deriva dalla mappatura del progetto o dalle scelte
     * correnti. Uno solo di questi computed lasciato in cache mostrerebbe un
     * riepilogo di un istante prima sotto un modulo aggiornato.
     */
    private function forgetMappingReads(): void
    {
        unset(
            $this->processRoles,
            $this->assignableUsers,
            $this->defaultAssignees,
            $this->overriddenAssignees,
            $this->effectiveProject,
            $this->uncoveredRoles,
            $this->inactiveResponsibles,
            $this->blockingReason,
            $this->overridable,
        );
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

        // Le scelte della release appena avviata non valgono per la prossima: ogni
        // release riparte dai responsabili del progetto, altrimenti una
        // sostituzione decisa per un'assenza si trascinerebbe nei rilasci successivi
        // senza che nessuno l'abbia piu confermata.
        $this->overrides = [];
        $this->primedDefaults = [];

        // `refresh()` ricarica anche le relazioni gia caricate: un `load()` qui
        // rifarebbe le stesse query una seconda volta.
        $this->project->refresh();

        $this->forgetMappingReads();
        unset($this->startedChain);

        $this->primeOverrides();
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

        <form wire:submit="start" class="space-y-5">
            <flux:card>
                <flux:input wire:model="label" :label="__('releases.label')"
                            :description="__('releases.label_help')"
                            :disabled="(bool) $this->blockingReason" required />
            </flux:card>

            {{--
                I responsabili si indicano qui e non dalla pagina della mappatura:
                un'assenza si gestisce nel momento in cui si avvia, e passare per la
                configurazione del progetto per una sostituzione di un giorno
                cambierebbe il default per tutti i rilasci successivi.

                La sezione compare solo quando il blocco residuo e di quelli che una
                scelta di persona risolve: con il progetto disattivato o il processo
                inutilizzabile non c'e nulla da indicare.
            --}}
            @if ($this->overridable && $this->processRoles->isNotEmpty())
                <div>
                    <flux:heading size="lg" level="2">{{ __('releases.override_heading') }}</flux:heading>
                    <flux:text class="mt-1 text-sm">{{ __('releases.override_explained') }}</flux:text>
                </div>

                {{-- Una scheda per ruolo, impilate a piena larghezza: a 375 px una
                     tabella con select dentro obbligherebbe allo scorrimento
                     orizzontale. Stessa scelta della pagina dei responsabili. --}}
                @foreach ($this->processRoles as $role)
                    @php
                        // Il valore di partenza e quello con cui il select e stato
                        // proposto, non la mappatura di adesso: e rispetto a quello
                        // che una scelta si legge come sostituzione.
                        $defaultId = $primedDefaults[$role->id] ?? '';
                        $isUncovered = $this->uncoveredRoles->contains('id', $role->id);
                    @endphp

                    <flux:card class="space-y-3">
                        <div>
                            <flux:heading size="lg" level="3">{{ $role->name }}</flux:heading>

                            @if ($role->description)
                                <flux:text class="mt-0.5 text-xs">{{ $role->description }}</flux:text>
                            @endif
                        </div>

                        <flux:select wire:model.live="overrides.{{ $role->id }}"
                                     :label="__('releases.override_role_label', ['role' => $role->name])"
                                     :description="$isUncovered ? __('releases.override_required') : null">
                            {{-- La voce vuota esiste solo dove ha un significato: un
                                 ruolo scoperto parte da "nessuno indicato". Offrirla
                                 su un ruolo coperto suggerirebbe di poter togliere il
                                 responsabile, che per una release non e previsto. --}}
                            @if ($defaultId === '')
                                <flux:select.option value="">
                                    {{ __('releases.override_unassigned_option') }}
                                </flux:select.option>
                            @endif

                            @foreach ($this->assignableUsers as $user)
                                @php
                                    $optionLabel = $user->id === $defaultId
                                        ? __('releases.override_default_option', ['name' => $user->name])
                                        : $user->name;

                                    $optionLabel = $user->is_active
                                        ? $optionLabel
                                        : __('releases.override_inactive_person', ['name' => $optionLabel]);
                                @endphp

                                <flux:select.option value="{{ $user->id }}">{{ $optionLabel }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:card>
                @endforeach
            @endif

            <flux:button type="submit" variant="primary" icon="rocket-launch"
                         :disabled="(bool) $this->blockingReason">
                {{ __('releases.start_action') }}
            </flux:button>
        </form>
    @endif
</div>
