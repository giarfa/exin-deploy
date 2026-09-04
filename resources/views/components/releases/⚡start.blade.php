<?php

use App\Actions\Releases\StartRelease;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\InactiveProjectCannotStartRelease;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\ProjectWithoutUsableTemplate;
use App\Exceptions\ResponsibleOverrideRefused;
use App\Exceptions\RolesWithoutResponsible;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\User;
use App\Rules\AssignableUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Avvio di una release su un progetto.
 *
 * Il caso normale resta **"compila l'etichetta e avvia"**: accanto ai ruoli
 * previsti dal processo compare il responsabile gia risolto dalla mappatura, e
 * chi avvia non deve scegliere nulla. La sovrascrittura e un'azione deliberata,
 * per l'assenza o la sostituzione di una persona su quel singolo rilascio, e non
 * tocca la configurazione condivisa (US-013, FR-029).
 *
 * Le precondizioni sono mostrate **prima** del tentativo. Da US-013 sono di due
 * tipi, e la differenza conta: progetto disattivato o processo inutilizzabile
 * **bloccano** — nessuna scelta qui li risolve, e il comando resta disabilitato
 * con il motivo accanto. Ruolo scoperto e responsabile disattivato **non**
 * bloccano piu: sono esattamente cio che i selettori qui sotto esistono per
 * coprire, e disabilitare il comando spegnerebbe la funzione nel punto in cui la
 * si userebbe.
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
     * Persona scelta per ciascun ruolo previsto dal processo, indicizzata per
     * identificativo di ruolo. Stringa vuota significa "nessuna scelta".
     *
     * Precompilata con i responsabili della mappatura: cio che si invia senza
     * toccare nulla e cio che l'avvio avrebbe risolto da solo, e la release
     * prodotta e indistinguibile da quella di prima di US-013.
     *
     * @var array<string, string>
     */
    public array $responsibles = [];

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
            'responsibles' => ['array'],
            // `AssignableUser` distingue gia inesistente da disattivato, ed e la
            // stessa regola della mappatura di progetto: la persona ammissibile e
            // una sola nozione, e due copie divergerebbero.
            'responsibles.*' => ['required', 'string', new AssignableUser],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        $attributes = ['label' => __('releases.label')];

        // Senza questa mappatura il messaggio direbbe "il campo responsibles.<uuid>":
        // il criterio chiede il **nome del ruolo** mancante, ed e quello che chi
        // legge deve poter cercare a schermo.
        foreach ($this->responsibleChoices as $choice) {
            $attributes["responsibles.{$choice['role']->id}"] = $choice['role']->name;
        }

        return $attributes;
    }

    /**
     * Ruoli previsti dal processo con il proprio responsabile predefinito, uno per
     * ruolo. La regola vive sul modello: serve anche altrove, e due copie
     * divergerebbero.
     *
     * @return list<array{role: \App\Models\Role, default: User|null, requires_choice: bool}>
     */
    #[Computed]
    public function responsibleChoices(): array
    {
        return $this->project->responsibleChoices();
    }

    /**
     * Persone selezionabili: i **soli membri attivi**.
     *
     * Divergenza deliberata da `projects/⚡assignments.blade.php`, che tiene in
     * elenco anche i disattivati gia assegnati perche la loro riga mostrerebbe
     * altrimenti qualcun altro. Li si descrive una responsabilita che esiste; qui
     * se ne crea una nuova, e una release non deve poter nascere in carico a chi
     * non accede piu. Chi trova le due pagine diverse non deve dedurne
     * un'incoerenza da uniformare.
     *
     * Una sola lista per tutti i selettori: il costo non cresce col numero di
     * ruoli.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    #[Computed]
    public function assignableUsers()
    {
        return User::query()
            ->select(['id', 'name'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Ruoli previsti dal processo che sul progetto non hanno un responsabile.
     *
     * Da US-013 e un avviso e non un blocco: il selettore del ruolo lo copre.
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
     * accede piu, e resterebbe fermo. Anche questo e un avviso, non un blocco.
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

    /**
     * Nessuna `Gate` qui, e deliberato: il montaggio e coperto dal `->can()` della
     * rotta, mentre `start()` e `startAnother()` hanno la propria — le azioni
     * Livewire non ripassano dal middleware. Aggiungerla anche al montaggio non
     * chiuderebbe alcun percorso e renderebbe non verificabile la guardia
     * sull'azione, che `ReleasePolicyTest::test_a_member_cannot_invoke_the_start_action_directly`
     * esercita proprio montando il componente e chiamando l'azione.
     */
    public function mount(): void
    {
        // Il processo serve gia al primo render, per il riepilogo delle
        // precondizioni e per i selettori: caricarlo qui evita che ogni lettura lo
        // ricarichi.
        $this->project->load([
            'workflowTemplate.stepDefinitions.role',
            'assignments.user',
        ]);

        $this->resetResponsibleSelections();
    }

    public function start(StartRelease $startRelease): void
    {
        Gate::authorize('create', [Release::class, $this->project]);

        /*
         * Le chiavi ammesse sono i soli ruoli previsti dal processo, e i valori
         * sono normalizzati a stringa: stesso trattamento di `save()` sulla pagina
         * dei responsabili. Un ruolo estraneo non deve nemmeno raggiungere
         * l'Action, che lo rifiuterebbe comunque — ma il rifiuto giusto qui e
         * "quel campo non esiste", non un messaggio di dominio.
         *
         * Le stringhe vuote **restano**: sono i ruoli senza scelta, e devono
         * produrre un errore `required` sul campo che li nomina.
         */
        $this->responsibles = collect($this->responsibles)
            ->only(collect($this->responsibleChoices)->map(fn (array $choice): string => $choice['role']->id)->all())
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
                $validated['responsibles'] ?? [],
            );
        } catch (InactiveProjectCannotStartRelease) {
            $this->refuse(__('releases.blocked_inactive_project'));

            return;
        } catch (ProjectWithoutUsableTemplate $refused) {
            $this->refuse(__($refused->reasonKey));

            return;
        } catch (ResponsibleOverrideRefused $refused) {
            // Ogni motivo torna sul campo del ruolo che lo ha generato: si corregge
            // dove si sbaglia. Il ruolo fuori processo non ha un campo a cui
            // agganciarsi — nel modulo non esiste — e diventa il messaggio in testa.
            foreach ($refused->reasonsByRole as $roleId => $reasonKey) {
                $this->addError("responsibles.{$roleId}", __($reasonKey, [
                    'role' => $refused->roleNames[$roleId] ?? $roleId,
                ]));
            }

            if ($refused->headKey !== null) {
                $this->refuse(__($refused->headKey));
            }

            return;
        } catch (RolesWithoutResponsible $refused) {
            foreach ($refused->roleIds as $index => $roleId) {
                $this->addError("responsibles.{$roleId}", __('releases.override_required', [
                    'role' => $refused->roleNames[$index] ?? $roleId,
                ]));
            }

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
        unset($this->uncoveredRoles, $this->inactiveResponsibles, $this->blockingReason, $this->responsibleChoices);

        // Le scelte gia fatte **non** si azzerano: perderle dopo un errore su un
        // solo campo obbligherebbe a ricompilare tutto il modulo.
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

        unset($this->uncoveredRoles, $this->inactiveResponsibles, $this->blockingReason, $this->startedChain, $this->responsibleChoices, $this->assignableUsers);

        // Un avvio non deve lasciare in giro le scelte del precedente: ogni release
        // riparte dai predefiniti, che e anche cio che il criterio di isolamento
        // chiede di poter dimostrare.
        $this->resetResponsibleSelections();
    }

    /**
     * Riporta i selettori sui responsabili predefiniti, freschi.
     *
     * Il predefinito `null` — ruolo scoperto o persona disattivata — diventa
     * stringa vuota: un campo da compilare, non un valore da inviare.
     */
    private function resetResponsibleSelections(): void
    {
        $this->responsibles = collect($this->responsibleChoices)
            ->mapWithKeys(fn (array $choice): array => [
                $choice['role']->id => (string) ($choice['default']?->id ?? ''),
            ])
            ->all();
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

                        {{-- Il responsabile indicato in avvio si dichiara con icona e
                             parola, mai dal solo colore. --}}
                        @if ($step->responsible_overridden)
                            <flux:text class="mt-1 inline-flex items-start gap-1.5 text-xs">
                                <flux:icon name="user-plus" variant="mini" class="mt-0.5 size-4 shrink-0" />
                                {{ __('releases.responsible_overridden_note') }}
                            </flux:text>
                        @endif

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

                {{-- I due avvisi qui sotto **non** bloccano: dicono cosa va indicato
                     nel modulo, e il comando resta attivo. Nominano i ruoli e le
                     persone perche "manca qualcosa" senza sapere cosa non aiuta a
                     risolverlo. --}}
                @if ($this->uncoveredRoles->isEmpty() && $this->inactiveResponsibles->isEmpty())
                    <flux:text class="inline-flex items-center gap-1.5 text-sm">
                        <flux:icon name="check-circle" variant="mini" class="size-4 shrink-0" />
                        {{ __('releases.precondition_roles_ok') }}
                    </flux:text>
                @else
                    @if ($this->uncoveredRoles->isNotEmpty())
                        <flux:text class="inline-flex items-start gap-1.5 text-sm">
                            <flux:icon name="exclamation-triangle" variant="mini" class="mt-0.5 size-4 shrink-0" />
                            {{ trans_choice('releases.warning_uncovered_roles', $this->uncoveredRoles->count(), [
                                'roles' => $this->uncoveredRoles->pluck('name')->implode(', '),
                            ]) }}
                        </flux:text>
                    @endif

                    @if ($this->inactiveResponsibles->isNotEmpty())
                        <flux:text class="inline-flex items-start gap-1.5 text-sm">
                            <flux:icon name="exclamation-triangle" variant="mini" class="mt-0.5 size-4 shrink-0" />
                            {{ trans_choice('releases.warning_inactive_responsibles', $this->inactiveResponsibles->count(), [
                                'members' => $this->inactiveResponsibles->pluck('name')->implode(', '),
                            ]) }}
                        </flux:text>
                    @endif

                    {{-- L'alternativa permanente: sistemare la mappatura una volta per
                         tutte invece che a ogni rilascio. --}}
                    <div>
                        <flux:button :href="route('projects.assignments', $project)" size="sm" variant="ghost">
                            {{ __('releases.blocked_hint_assignments') }}
                        </flux:button>
                    </div>
                @endif
            @endif
        </flux:card>

        <flux:card class="space-y-5">
            <form wire:submit="start" class="space-y-5">
                <flux:input wire:model="label" :label="__('releases.label')"
                            :description="__('releases.label_help')"
                            :disabled="(bool) $this->blockingReason" required />

                @if (! $this->blockingReason && $this->responsibleChoices !== [])
                    <div class="space-y-4">
                        <div>
                            <flux:heading size="lg" level="2">{{ __('releases.responsibles_heading') }}</flux:heading>
                            <flux:text class="mt-1 text-sm">{{ __('releases.responsibles_help') }}</flux:text>
                        </div>

                        {{-- Schede impilate a piena larghezza: a 375 px una griglia
                             affiancata obbligherebbe allo scorrimento orizzontale. --}}
                        @foreach ($this->responsibleChoices as $choice)
                            <flux:card class="space-y-2">
                                {{-- Il ruolo da indicare e distinto da icona e parola,
                                     mai dal solo colore. --}}
                                @if ($choice['requires_choice'])
                                    <flux:text class="inline-flex items-start gap-1.5 text-xs">
                                        <flux:icon name="exclamation-triangle" variant="mini"
                                                   class="mt-0.5 size-4 shrink-0" />
                                        {{ __('releases.responsible_choice_required') }}
                                    </flux:text>
                                @endif

                                <flux:select
                                    wire:model="responsibles.{{ $choice['role']->id }}"
                                    :label="$choice['role']->name"
                                    class="min-h-11"
                                >
                                    @if ($choice['requires_choice'])
                                        <flux:select.option value="">
                                            {{ __('releases.responsible_choose_option') }}
                                        </flux:select.option>
                                    @endif

                                    @foreach ($this->assignableUsers as $user)
                                        <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:card>
                        @endforeach
                    </div>
                @endif

                <flux:button type="submit" variant="primary" icon="rocket-launch"
                             :disabled="(bool) $this->blockingReason">
                    {{ __('releases.start_action') }}
                </flux:button>
            </form>
        </flux:card>
    @endif
</div>
