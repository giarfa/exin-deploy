<?php

use App\Models\DefaultRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Rules\AssignableUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Mappatura predefinita ruolo -> persona valida per il team.
 *
 * E la sorgente della precompilazione: la copia avviene una sola volta, alla
 * creazione di un progetto. Modificarla qui **non** si propaga ai progetti
 * esistenti, ed e il punto che va detto in modo esplicito nell'interfaccia:
 * aspettarsi la propagazione e l'equivoco naturale.
 */
new class extends Component
{
    /**
     * Persona scelta per ciascun ruolo, indicizzata per identificativo di ruolo.
     * Stringa vuota significa "nessuna persona predefinita".
     *
     * @var array<string, string>
     */
    public array $selections = [];

    public ?string $feedback = null;

    public function mount(): void
    {
        $this->selections = $this->roles
            ->mapWithKeys(fn (Role $role): array => [
                $role->id => (string) ($this->assignments[$role->id]->user_id ?? ''),
            ])
            ->all();
    }

    /**
     * Solo i ruoli attivi: un ruolo disattivato non va proposto come predefinito
     * su progetti che ancora non esistono.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    #[Computed]
    public function roles()
    {
        return Role::query()->active()->orderBy('name')->get();
    }

    /**
     * Mappatura corrente indicizzata per ruolo, con la persona gia caricata.
     *
     * @return \Illuminate\Support\Collection<string, DefaultRoleAssignment>
     */
    #[Computed]
    public function assignments()
    {
        return DefaultRoleAssignment::query()
            ->with('user:id,name,is_active')
            ->get()
            ->keyBy('role_id');
    }

    /**
     * Persone selezionabili: i membri attivi, piu quelli disattivati gia indicati
     * come predefiniti — altrimenti la loro riga mostrerebbe qualcun altro.
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
            'selections.*' => ['nullable', 'string', new AssignableUser],
        ];
    }

    public function save(): void
    {
        Gate::authorize('create', DefaultRoleAssignment::class);

        $allowedRoles = $this->roles->pluck('id');

        // Solo i ruoli in elenco: senza questo filtro si potrebbe scrivere una
        // predefinita per un ruolo disattivato, indicandolo per identificativo.
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
                    DefaultRoleAssignment::query()->where('role_id', $roleId)->delete();

                    continue;
                }

                // `updateOrCreate` sulla chiave del ruolo, coerente con l'indice
                // unico che il database applica sulla stessa colonna.
                DefaultRoleAssignment::updateOrCreate(
                    ['role_id' => $roleId],
                    ['user_id' => $userId],
                );
            }
        });

        unset($this->assignments, $this->assignableUsers);

        $this->feedback = __('assignments.saved');
    }
};
?>

<div>
    <flux:heading size="xl" level="1">{{ __('assignments.heading') }}</flux:heading>
    <flux:text class="mt-1">{{ __('assignments.description') }}</flux:text>

    {{-- Non e una nota di contorno: aspettarsi che la modifica si propaghi ai
         progetti esistenti e l'equivoco naturale, e va tolto di mezzo subito. --}}
    <flux:callout icon="information-circle" class="mt-6">
        {{ __('assignments.not_retroactive') }}
    </flux:callout>

    @if ($feedback)
        <flux:callout variant="success" icon="check-circle" class="mt-4" aria-live="polite">
            {{ $feedback }}
        </flux:callout>
    @endif

    @if ($this->roles->isEmpty())
        <flux:callout icon="information-circle" class="mt-6">{{ __('assignments.no_roles') }}</flux:callout>
    @else
        <form wire:submit="save" class="mt-6 space-y-5">
            @foreach ($this->roles as $role)
                @php($assignment = $this->assignments[$role->id] ?? null)

                <flux:card class="space-y-3">
                    <div>
                        <flux:heading size="lg" level="2">{{ $role->name }}</flux:heading>

                        @if ($role->description)
                            <flux:text class="mt-0.5 text-xs">{{ $role->description }}</flux:text>
                        @endif
                    </div>

                    <flux:select wire:model="selections.{{ $role->id }}" :label="__('assignments.person')">
                        <flux:select.option value="">{{ __('assignments.unassigned_option') }}</flux:select.option>

                        @foreach ($this->assignableUsers as $user)
                            <flux:select.option value="{{ $user->id }}">
                                {{ $user->name }}{{ $user->is_active ? '' : ' — '.__('roles.inactive') }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($assignment && ! $assignment->user->is_active)
                        <flux:text class="inline-flex items-center gap-1.5 text-xs">
                            <flux:icon name="exclamation-triangle" variant="mini" class="size-4 shrink-0" />
                            {{ $assignment->user->name }} — {{ __('assignments.inactive_person_note') }}
                        </flux:text>
                    @endif
                </flux:card>
            @endforeach

            <flux:button type="submit" variant="primary">{{ __('assignments.save') }}</flux:button>
        </form>
    @endif
</div>
