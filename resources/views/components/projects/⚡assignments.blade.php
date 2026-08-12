<?php

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Rules\AssignableUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Mappatura ruolo -> persona di un singolo progetto.
 *
 * All'avvio di una release ogni step risolve il proprio ruolo in una persona
 * leggendo queste righe: un ruolo scoperto qui diventa uno step senza responsabile.
 *
 * La pagina itera sui **ruoli** e non sulle assegnazioni esistenti, cosi che un
 * ruolo creato dopo il progetto compaia da solo come non assegnato.
 */
new class extends Component
{
    /** Progetto risolto dal binding di rotta. */
    public Project $project;

    /**
     * Persona scelta per ciascun ruolo, indicizzata per identificativo di ruolo.
     * Stringa vuota significa "nessun responsabile".
     *
     * @var array<string, string>
     */
    public array $selections = [];

    public ?string $feedback = null;

    /**
     * Ruoli previsti dal template e senza responsabile su questo progetto.
     *
     * Le relazioni sono precaricate qui una volta sola: `uncoveredRoles()` non
     * deve interrogare il database mentre la vista disegna il banner.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    #[Computed]
    public function uncoveredRoles()
    {
        /*
         * Le assegnazioni sono gia state lette dal computed `assignments`: gliele
         * si passa invece di rileggerle, altrimenti la stessa pagina
         * interrogherebbe due volte la stessa tabella per disegnare due riquadri.
         */
        $this->project->setRelation('assignments', $this->assignments->values());
        $this->project->loadMissing('workflowTemplate.stepDefinitions.role:id,name');

        return $this->project->uncoveredRoles();
    }

    /**
     * Numero di ruoli ricoperti da ciascuna persona su questo progetto,
     * indicizzato per identificativo della persona.
     *
     * Il cumulo di ruoli e voluto: il vincolo unico e su (progetto, ruolo) e non
     * esiste su (progetto, persona). Vedendo la stessa persona su piu righe senza
     * spiegazione sembrerebbe un difetto, e qualcuno "correggerebbe" il dato.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    #[Computed]
    public function rolesPerUser()
    {
        return $this->assignments
            ->groupBy('user_id')
            ->map(fn ($group): int => $group->count())
            ->filter(fn (int $count): bool => $count > 1);
    }

    public function mount(): void
    {
        $this->selections = $this->roles
            ->mapWithKeys(fn (Role $role): array => [
                $role->id => (string) ($this->assignments[$role->id]->user_id ?? ''),
            ])
            ->all();
    }

    /**
     * Ruoli mostrati: quelli attivi, piu quelli disattivati che hanno gia un
     * responsabile su questo progetto. Nascondere questi ultimi renderebbe
     * invisibile una responsabilita che esiste.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    #[Computed]
    public function roles()
    {
        return Role::query()
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhereHas(
                        'projectAssignments',
                        fn ($assignments) => $assignments->where('project_id', $this->project->id)
                    );
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Assegnazioni correnti indicizzate per ruolo, con la persona gia caricata.
     *
     * @return \Illuminate\Support\Collection<string, \App\Models\ProjectRoleAssignment>
     */
    #[Computed]
    public function assignments()
    {
        return $this->project->assignments()
            ->with('user:id,name,is_active')
            ->get()
            ->keyBy('role_id');
    }

    /**
     * Persone selezionabili: i membri attivi, piu quelli disattivati gia assegnati
     * su questo progetto — altrimenti la loro riga mostrerebbe qualcun altro.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    #[Computed]
    public function assignableUsers()
    {
        $assignedIds = $this->assignments->pluck('user_id')->all();

        return User::query()
            ->select(['id', 'name', 'is_active'])
            ->where('is_active', true)
            ->orWhereIn('id', $assignedIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'selections' => ['array'],
            // Le chiavi ammesse sono solo i ruoli mostrati: senza questo vincolo si
            // potrebbe assegnare per identificativo un ruolo disattivato e non in elenco.
            'selections.*' => ['nullable', 'string', new AssignableUser],
        ];
    }

    public function save(): void
    {
        Gate::authorize('manageAssignments', $this->project);

        $allowedRoles = $this->roles->pluck('id');

        $this->selections = collect($this->selections)
            ->only($allowedRoles->all())
            ->map(fn (mixed $userId): string => (string) $userId)
            ->reject(fn (string $userId): bool => $userId === '')
            ->all();

        $this->validate();

        DB::transaction(function () use ($allowedRoles): void {
            foreach ($allowedRoles as $roleId) {
                $userId = $this->selections[$roleId] ?? '';

                if ($userId === '') {
                    $this->project->assignments()->where('role_id', $roleId)->delete();

                    continue;
                }

                $this->project->assignments()->updateOrCreate(
                    ['role_id' => $roleId],
                    ['user_id' => $userId],
                );
            }
        });

        unset($this->assignments, $this->assignableUsers, $this->uncoveredRoles, $this->rolesPerUser);
        $this->project->unsetRelation('assignments')->unsetRelation('workflowTemplate');

        $this->feedback = __('projects.assignment_saved');
    }
};
?>

<div>
    <flux:button :href="route('projects.index')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('projects.back_to_projects') }}
    </flux:button>

    <flux:heading size="xl" level="1">
        {{ __('projects.assignments_heading', ['project' => $project->name]) }}
    </flux:heading>
    <flux:text class="mt-1">{{ __('projects.assignments_description') }}</flux:text>

    @if ($feedback)
        <flux:callout variant="success" icon="check-circle" class="mt-6" aria-live="polite">
            {{ $feedback }}
        </flux:callout>
    @endif

    @if ($this->uncoveredRoles->isNotEmpty())
        {{-- La segnalazione nomina i ruoli e la conseguenza: senza il "cosa
             succede se lo lascio cosi" resterebbe un avviso da ignorare. --}}
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
            {{ trans_choice('projects.uncovered_roles', $this->uncoveredRoles->count(), [
                'roles' => $this->uncoveredRoles->pluck('name')->join(', ', ' e '),
            ]) }}
        </flux:callout>
    @endif

    @if ($project->workflowTemplate && $templateReason = $project->workflowTemplate->unusableReason())
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
            {{ $project->workflowTemplate->name }} — {{ __($templateReason) }}
        </flux:callout>
    @endif

    @if ($this->roles->isEmpty())
        <flux:callout icon="information-circle" class="mt-6">{{ __('projects.no_roles') }}</flux:callout>
    @else
        <form wire:submit="save" class="mt-6 space-y-5">
            {{-- Elenco di schede e non tabella: a 375 px una tabella con select
                 dentro obbligherebbe allo scorrimento orizzontale. --}}
            @foreach ($this->roles as $role)
                @php($assignment = $this->assignments[$role->id] ?? null)

                <flux:card class="space-y-3">
                    <div>
                        <flux:heading size="lg" level="2">{{ $role->name }}</flux:heading>

                        @if ($role->description)
                            <flux:text class="mt-0.5 text-xs">{{ $role->description }}</flux:text>
                        @endif

                        @unless ($role->is_active)
                            <flux:text class="mt-1 inline-flex items-center gap-1.5 text-xs">
                                <flux:icon name="no-symbol" variant="mini" class="size-4 shrink-0" />
                                {{ __('projects.inactive_role_note') }}
                            </flux:text>
                        @endunless
                    </div>

                    <flux:select wire:model="selections.{{ $role->id }}" :label="__('projects.person')">
                        <flux:select.option value="">{{ __('projects.unassigned_option') }}</flux:select.option>

                        @foreach ($this->assignableUsers as $user)
                            <flux:select.option value="{{ $user->id }}">
                                {{ $user->name }}{{ $user->is_active ? '' : ' — '.__('roles.inactive') }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($assignment && ! $assignment->user->is_active)
                        <flux:text class="inline-flex items-center gap-1.5 text-xs">
                            <flux:icon name="exclamation-triangle" variant="mini" class="size-4 shrink-0" />
                            {{ $assignment->user->name }} — {{ __('projects.inactive_person_note') }}
                        </flux:text>
                    @endif

                    {{-- Il cumulo di ruoli sulla stessa persona e intenzionale: detto
                         qui, la ripetizione si legge come scelta e non come difetto. --}}
                    @if ($assignment && $this->rolesPerUser->has($assignment->user_id))
                        <flux:text class="inline-flex items-center gap-1.5 text-xs">
                            <flux:icon name="information-circle" variant="mini" class="size-4 shrink-0" />
                            {{ __('projects.multiple_roles', [
                                'name' => $assignment->user->name,
                                'count' => $this->rolesPerUser[$assignment->user_id],
                            ]) }}
                        </flux:text>
                    @endif
                </flux:card>
            @endforeach

            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('projects.save') }}</flux:button>
            </div>
        </form>

        <flux:text class="mt-6 text-xs">{{ __('projects.defaults_note') }}</flux:text>
    @endif
</div>
