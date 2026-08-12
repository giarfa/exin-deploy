<?php

use App\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Catalogo dei ruoli funzionali del processo di rilascio.
 *
 * Ogni operazione che muta lo stato passa da `Gate::authorize`: l'interfaccia
 * nasconde i comandi non disponibili, ma non e quello il controllo.
 */
new class extends Component
{
    /** Ruolo in modifica; `null` quando si sta creando. */
    public ?string $editingId = null;

    public bool $showingForm = false;

    public string $name = '';

    public string $description = '';

    /** Messaggio del rifiuto di una cancellazione, con il motivo per esteso. */
    public ?string $deletionError = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'name' => __('roles.name'),
            'description' => __('roles.role_description'),
        ];
    }

    /**
     * Ruoli in ordine alfabetico, con il conteggio dei riferimenti gia caricato:
     * la colonna "utilizzo" e l'ammissibilita della cancellazione si leggono da
     * qui, senza una query per riga.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    #[Computed]
    public function roles()
    {
        return Role::query()
            ->withCount(['projectAssignments', 'defaultAssignment'])
            ->orderBy('name')
            ->get();
    }

    public function openCreateForm(): void
    {
        Gate::authorize('create', Role::class);

        $this->reset(['editingId', 'name', 'description', 'deletionError']);
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function openEditForm(string $id): void
    {
        $role = Role::findOrFail($id);

        Gate::authorize('update', $role);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->deletionError = null;
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function closeForm(): void
    {
        $this->showingForm = false;
        $this->reset(['editingId', 'name', 'description']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $role = $this->editingId ? Role::findOrFail($this->editingId) : null;

        Gate::authorize($role ? 'update' : 'create', $role ?? Role::class);

        $validated = $this->validate();

        $attributes = [
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
        ];

        if ($role) {
            $role->update($attributes);
        } else {
            Role::create($attributes + ['is_active' => true]);
        }

        unset($this->roles);
        $this->closeForm();
    }

    public function toggleActivation(string $id): void
    {
        $role = Role::findOrFail($id);

        Gate::authorize('toggleActivation', $role);

        $role->update(['is_active' => ! $role->is_active]);

        unset($this->roles);
    }

    /**
     * Cancella un ruolo, se nessuno lo referenzia.
     *
     * Il rifiuto non e un errore generico: l'utente deve sapere **cosa** blocca la
     * cancellazione e che la disattivazione resta possibile, altrimenti riprova
     * all'infinito o cancella qualcos'altro.
     */
    public function delete(string $id): void
    {
        $role = Role::findOrFail($id);

        try {
            Gate::authorize('delete', $role);
        } catch (AuthorizationException) {
            $this->deletionError = __('roles.delete_refused', [
                'name' => $role->name,
                'usage' => $this->usageLabel($role),
            ]);

            return;
        }

        $role->delete();

        $this->deletionError = null;
        unset($this->roles);
    }

    /**
     * Descrizione leggibile di dove il ruolo e usato.
     */
    public function usageLabel(Role $role): string
    {
        $counts = $role->referenceCounts();
        $parts = [];

        if ($counts['projectAssignments'] > 0) {
            $parts[] = trans_choice('roles.used_projects', $counts['projectAssignments'], [
                'count' => $counts['projectAssignments'],
            ]);
        }

        if ($counts['defaultAssignment'] > 0) {
            $parts[] = __('roles.used_default');
        }

        return $parts === [] ? __('roles.unused') : implode(', ', $parts);
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('roles.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('roles.description') }}</flux:text>
        </div>

        <flux:button wire:click="openCreateForm" variant="primary" icon="plus">
            {{ __('roles.create_action') }}
        </flux:button>
    </div>

    @if ($deletionError)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" aria-live="polite">
            {{ $deletionError }}
        </flux:callout>
    @endif

    @if ($showingForm)
        <flux:card class="mb-6 space-y-5">
            <flux:heading size="lg" level="2">
                {{ $editingId ? __('roles.edit_heading') : __('roles.create_heading') }}
            </flux:heading>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('roles.name')"
                            :description="__('roles.name_help')" required />

                <flux:textarea wire:model="description" :label="__('roles.role_description')"
                               :description="__('roles.description_help')" rows="3" />

                <div class="flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary">{{ __('roles.save') }}</flux:button>
                    <flux:button type="button" wire:click="closeForm" variant="ghost">
                        {{ __('roles.cancel') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($this->roles->isEmpty())
        <flux:callout icon="information-circle">{{ __('roles.empty') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('roles.name') }}</flux:table.column>
                <flux:table.column>{{ __('roles.status') }}</flux:table.column>
                <flux:table.column>{{ __('roles.usage') }}</flux:table.column>
                <flux:table.column class="text-end">{{ __('roles.actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->roles as $role)
                    <flux:table.row :key="$role->id">
                        <flux:table.cell variant="strong">
                            {{ $role->name }}

                            @if ($role->description)
                                <flux:text class="mt-0.5 text-xs">{{ $role->description }}</flux:text>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            {{-- Stato reso con icona e parola, mai dal solo colore. --}}
                            <span class="inline-flex items-center gap-1.5 text-sm">
                                <flux:icon
                                    :name="$role->is_active ? 'check-circle' : 'no-symbol'"
                                    variant="mini"
                                    class="size-4 shrink-0"
                                />
                                {{ $role->is_active ? __('roles.active') : __('roles.inactive') }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell class="text-sm">{{ $this->usageLabel($role) }}</flux:table.cell>

                        <flux:table.cell class="text-end whitespace-nowrap">
                            <flux:button wire:click="openEditForm('{{ $role->id }}')" size="sm" variant="ghost">
                                {{ __('roles.edit') }}
                            </flux:button>

                            <flux:button
                                wire:click="toggleActivation('{{ $role->id }}')"
                                wire:confirm="{{ $role->is_active ? __('roles.confirm_deactivate', ['name' => $role->name]) : __('roles.confirm_activate', ['name' => $role->name]) }}"
                                size="sm"
                                variant="ghost"
                            >
                                {{ $role->is_active ? __('roles.deactivate') : __('roles.activate') }}
                            </flux:button>

                            @can('delete', $role)
                                <flux:button
                                    wire:click="delete('{{ $role->id }}')"
                                    wire:confirm="{{ __('roles.confirm_delete', ['name' => $role->name]) }}"
                                    size="sm"
                                    variant="ghost"
                                >
                                    {{ __('roles.delete') }}
                                </flux:button>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
