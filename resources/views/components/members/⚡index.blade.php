<?php

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Gestione dei membri del team.
 *
 * Ogni operazione che muta lo stato passa da `Gate::authorize`: l'interfaccia
 * nasconde i comandi non disponibili, ma non e quello il controllo.
 */
new class extends Component
{
    /** Membro in modifica; `null` quando si sta creando. */
    public ?string $editingId = null;

    public bool $showingForm = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $level = 'member';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->editingId),
            ],
            'level' => ['required', Rule::enum(UserLevel::class)],
            'password' => $this->editingId
                ? ['nullable', 'string', Password::default()]
                : ['required', 'string', Password::default()],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function members()
    {
        return User::query()
            ->select(['id', 'name', 'email', 'level', 'is_active'])
            ->orderByDesc('level')
            ->orderBy('name')
            ->get();
    }

    public function openCreateForm(): void
    {
        Gate::authorize('create', User::class);

        $this->reset(['editingId', 'name', 'email', 'password', 'level']);
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function openEditForm(string $id): void
    {
        $member = User::findOrFail($id);

        Gate::authorize('update', $member);

        $this->editingId = $member->id;
        $this->name = $member->name;
        $this->email = $member->email;
        $this->level = $member->level->value;
        $this->password = '';
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function closeForm(): void
    {
        $this->showingForm = false;
        $this->reset(['editingId', 'name', 'email', 'password', 'level']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $member = $this->editingId ? User::findOrFail($this->editingId) : null;

        Gate::authorize($member ? 'update' : 'create', $member ?? User::class);

        $validated = $this->validate();

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'level' => $validated['level'],
        ];

        if ($validated['password'] !== '') {
            $attributes['password'] = $validated['password'];
        }

        if ($member) {
            $member->update($attributes);
        } else {
            User::create($attributes + ['is_active' => true]);
        }

        unset($this->members);
        $this->closeForm();

        $this->dispatch('membro-salvato');
    }

    public function toggleActivation(string $id): void
    {
        $member = User::findOrFail($id);

        Gate::authorize('toggleActivation', $member);

        $member->update(['is_active' => ! $member->is_active]);

        unset($this->members);
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('members.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('members.description') }}</flux:text>
        </div>

        <flux:button wire:click="openCreateForm" variant="primary" icon="plus">
            {{ __('members.create_action') }}
        </flux:button>
    </div>

    @if ($showingForm)
        <flux:card class="mb-6 space-y-5">
            <flux:heading size="lg" level="2">
                {{ $editingId ? __('members.edit_heading') : __('members.create_heading') }}
            </flux:heading>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('members.name')" required />

                <flux:input wire:model="email" type="email" :label="__('members.email')" required />

                <flux:select wire:model="level" :label="__('members.level')" required>
                    @foreach (UserLevel::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="password"
                    type="password"
                    :label="$editingId ? __('members.password_optional') : __('members.password')"
                    :description="__('auth.password_requirements')"
                    :required="! $editingId"
                    viewable
                />

                <div class="flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary">{{ __('members.save') }}</flux:button>
                    <flux:button type="button" wire:click="closeForm" variant="ghost">
                        {{ __('members.cancel') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('members.name') }}</flux:table.column>
            <flux:table.column>{{ __('members.email') }}</flux:table.column>
            <flux:table.column>{{ __('members.level') }}</flux:table.column>
            <flux:table.column>{{ __('members.status') }}</flux:table.column>
            <flux:table.column class="text-end">{{ __('members.actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->members as $member)
                <flux:table.row :key="$member->id">
                    <flux:table.cell variant="strong">{{ $member->name }}</flux:table.cell>

                    <flux:table.cell class="break-all">{{ $member->email }}</flux:table.cell>

                    <flux:table.cell>
                        <flux:badge size="sm" :color="$member->isAdministrator() ? 'zinc' : 'zinc'"
                                    :variant="$member->isAdministrator() ? 'solid' : 'outline'">
                            {{ $member->level->label() }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell>
                        {{-- Stato reso con icona e parola, mai dal solo colore. --}}
                        <span class="inline-flex items-center gap-1.5 text-sm">
                            <flux:icon
                                :name="$member->is_active ? 'check-circle' : 'no-symbol'"
                                variant="mini"
                                class="size-4 shrink-0"
                            />
                            {{ $member->is_active ? __('members.active') : __('members.inactive') }}
                        </span>
                    </flux:table.cell>

                    <flux:table.cell class="text-end whitespace-nowrap">
                        <flux:button wire:click="openEditForm('{{ $member->id }}')" size="sm" variant="ghost">
                            {{ __('members.edit') }}
                        </flux:button>

                        @can('toggleActivation', $member)
                            <flux:button
                                wire:click="toggleActivation('{{ $member->id }}')"
                                wire:confirm="{{ $member->is_active ? __('members.confirm_deactivate', ['name' => $member->name]) : __('members.confirm_activate', ['name' => $member->name]) }}"
                                size="sm"
                                variant="ghost"
                            >
                                {{ $member->is_active ? __('members.deactivate') : __('members.activate') }}
                            </flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:text class="mt-4 text-xs">{{ __('members.no_deletion_note') }}</flux:text>
</div>
