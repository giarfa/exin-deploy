<?php

use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Sequenza di step di un template di workflow.
 *
 * Il riordino e reso da comandi **sposta su / sposta giu** e non da
 * trascinamento: e raggiungibile da tastiera, funziona a 375 px e non richiede
 * test browser. La contiguita delle posizioni non e responsabilita di questo
 * componente ma del concern `OrderedByPosition`: qui si delega e basta.
 *
 * Step e campi non hanno una Policy propria: ogni azione e decisa da
 * `manageSteps` sul template che li contiene.
 */
new class extends Component
{
    /** Template risolto dal binding di rotta. */
    public WorkflowTemplate $template;

    /** Step in modifica; `null` quando si sta creando. */
    public ?string $editingId = null;

    public bool $showingForm = false;

    public string $name = '';

    public string $instructions = '';

    public string $roleId = '';

    /** Conferma dell'ultimo spostamento, annunciata a chi usa uno screen reader. */
    public ?string $feedback = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            // La chiave del ruolo e validata contro le **sole opzioni in elenco**
            // e non contro l'intera tabella: e la difesa contro l'indicazione per
            // identificativo di un ruolo che qui non sarebbe proponibile.
            'roleId' => ['required', 'string', Rule::in($this->assignableRoles->pluck('id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'name' => __('templates.step_name'),
            'instructions' => __('templates.step_instructions'),
            'roleId' => __('templates.step_role'),
        ];
    }

    /**
     * Step del template, con il ruolo e il numero di campi gia caricati: la
     * colonna "campi richiesti" non deve costare una query per riga.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StepDefinition>
     */
    #[Computed]
    public function steps()
    {
        return $this->template->stepDefinitions()
            ->with('role:id,name,is_active')
            ->withCount('fieldDefinitions')
            ->get();
    }

    /**
     * Ruoli proponibili: quelli attivi, piu quelli disattivati gia usati da uno
     * step di questo template. Nasconderli renderebbe invisibile una
     * responsabilita che esiste davvero.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    #[Computed]
    public function assignableRoles()
    {
        return Role::query()
            ->select(['id', 'name', 'is_active'])
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhereHas(
                        'stepDefinitions',
                        fn ($steps) => $steps->where('workflow_template_id', $this->template->id)
                    );
            })
            ->orderBy('name')
            ->get();
    }

    public function openCreateForm(): void
    {
        Gate::authorize('manageSteps', $this->template);

        $this->reset(['editingId', 'name', 'instructions', 'roleId', 'feedback']);
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function openEditForm(string $id): void
    {
        Gate::authorize('manageSteps', $this->template);

        $step = $this->findStep($id);

        $this->editingId = $step->id;
        $this->name = $step->name;
        $this->instructions = (string) $step->instructions;
        $this->roleId = $step->role_id;
        $this->feedback = null;
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function closeForm(): void
    {
        $this->showingForm = false;
        $this->reset(['editingId', 'name', 'instructions', 'roleId']);
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manageSteps', $this->template);

        $validated = $this->validate();

        $attributes = [
            'name' => $validated['name'],
            'instructions' => $validated['instructions'] !== '' ? $validated['instructions'] : null,
            'role_id' => $validated['roleId'],
        ];

        if ($this->editingId) {
            $this->findStep($this->editingId)->update($attributes);
        } else {
            // `make()` assegna gia la chiave esterna, quindi `nextPosition()` sa
            // qual e la sequenza in cui il nuovo step si inserisce.
            $step = $this->template->stepDefinitions()->make($attributes);
            $step->position = $step->nextPosition();
            $step->save();
        }

        unset($this->steps);
        $this->closeForm();
    }

    public function moveUp(string $id): void
    {
        $this->move($id, up: true);
    }

    public function moveDown(string $id): void
    {
        $this->move($id, up: false);
    }

    public function delete(string $id): void
    {
        Gate::authorize('manageSteps', $this->template);

        // La cancellazione passa da `deleteAndResequence()`: le posizioni restano
        // contigue senza che questo componente se ne debba occupare.
        $this->findStep($id)->deleteAndResequence();

        $this->feedback = null;
        unset($this->steps);
    }

    private function move(string $id, bool $up): void
    {
        Gate::authorize('manageSteps', $this->template);

        $step = $this->findStep($id);

        $up ? $step->moveUp() : $step->moveDown();

        $this->feedback = __('templates.moved', [
            'name' => $step->name,
            'position' => $step->fresh()->position,
        ]);

        unset($this->steps);
    }

    /**
     * Lo step viene cercato **dentro** il template della rotta: uno step di un
     * altro template non e raggiungibile passandone l'identificativo a un'azione.
     */
    private function findStep(string $id): StepDefinition
    {
        return $this->template->stepDefinitions()->findOrFail($id);
    }
};
?>

<div>
    <flux:button :href="route('templates.index')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('templates.back_to_templates') }}
    </flux:button>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">
                {{ __('templates.steps_heading', ['template' => $template->name]) }}
            </flux:heading>
            <flux:text class="mt-1">{{ __('templates.steps_description') }}</flux:text>
        </div>

        @if ($this->assignableRoles->isNotEmpty())
            <flux:button wire:click="openCreateForm" variant="primary" icon="plus">
                {{ __('templates.step_create_action') }}
            </flux:button>
        @endif
    </div>

    {{-- Lo spostamento cambia l'ordine senza spostare il fuoco: senza annuncio
         chi non vede lo schermo non saprebbe che e successo qualcosa. --}}
    <div aria-live="polite" class="sr-only">{{ $feedback }}</div>

    @if ($feedback)
        <flux:callout variant="success" icon="check-circle" class="mb-6" aria-hidden="true">
            {{ $feedback }}
        </flux:callout>
    @endif

    {{-- Il catalogo dei ruoli vuoto impedisce di aggiungere step, ma non deve
         nascondere lo stato del template: senza step resta inutilizzabile, e
         chi guarda deve continuare a vederlo detto. --}}
    @if ($this->assignableRoles->isEmpty())
        <flux:callout icon="information-circle" class="mb-6">{{ __('templates.no_roles') }}</flux:callout>
    @endif

    @if ($this->assignableRoles->isNotEmpty())
        @if ($showingForm)
            <flux:card class="mb-6 space-y-5">
                <flux:heading size="lg" level="2">
                    {{ $editingId ? __('templates.step_edit_heading') : __('templates.step_create_heading') }}
                </flux:heading>

                <form wire:submit="save" class="space-y-5">
                    <flux:input wire:model="name" :label="__('templates.step_name')"
                                :description="__('templates.step_name_help')" required />

                    {{-- Le istruzioni sono in evidenza e non un dettaglio nascosto:
                         sono il punto in cui il processo diventa autoesplicativo. --}}
                    <flux:textarea wire:model="instructions" :label="__('templates.step_instructions')"
                                   :description="__('templates.step_instructions_help')" rows="4" />

                    <flux:select wire:model="roleId" :label="__('templates.step_role')"
                                 :description="__('templates.step_role_help')" required>
                        <flux:select.option value="">—</flux:select.option>

                        @foreach ($this->assignableRoles as $role)
                            <flux:select.option value="{{ $role->id }}">
                                {{ $role->name }}{{ $role->is_active ? '' : ' — '.__('templates.inactive') }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex flex-wrap gap-3">
                        <flux:button type="submit" variant="primary">{{ __('templates.save') }}</flux:button>
                        <flux:button type="button" wire:click="closeForm" variant="ghost">
                            {{ __('templates.cancel') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        @endif
    @endif

    @if ($this->steps->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('templates.steps_empty') }}
        </flux:callout>
    @else
        {{-- Elenco di schede e non tabella: a 375 px una tabella con istruzioni
             e quattro comandi per riga imporrebbe lo scorrimento orizzontale. --}}
        <ol class="space-y-4">
            @foreach ($this->steps as $step)
                <li>
                    <flux:card class="space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <flux:text class="text-xs">
                                    {{ __('templates.step_position', ['position' => $step->position]) }}
                                </flux:text>

                                <flux:heading size="lg" level="2">{{ $step->name }}</flux:heading>

                                <flux:text class="mt-1 inline-flex items-center gap-1.5 text-sm">
                                    <flux:icon name="identification" variant="mini" class="size-4 shrink-0" />
                                    {{ $step->role->name }}
                                </flux:text>

                                @unless ($step->role->is_active)
                                    <flux:text class="mt-1 inline-flex items-center gap-1.5 text-xs">
                                        <flux:icon name="no-symbol" variant="mini" class="size-4 shrink-0" />
                                        {{ __('templates.inactive_role_note') }}
                                    </flux:text>
                                @endunless
                            </div>

                            {{-- Comandi di riordino. L'etichetta nomina lo step spostato e
                                 non e solo una freccia. Agli estremi il comando resta
                                 raggiungibile ma annunciato come non disponibile
                                 (`aria-disabled`), e l'operazione e comunque un nulla di
                                 fatto: la regola vive nel concern, non qui. --}}
                            <div class="flex shrink-0 gap-1">
                                <flux:button
                                    wire:click="moveUp('{{ $step->id }}')"
                                    icon="arrow-up"
                                    size="sm"
                                    variant="ghost"
                                    :aria-disabled="$loop->first ? 'true' : 'false'"
                                    :class="$loop->first ? 'opacity-40' : ''"
                                    :aria-label="__('templates.move_up', ['name' => $step->name])"
                                />

                                <flux:button
                                    wire:click="moveDown('{{ $step->id }}')"
                                    icon="arrow-down"
                                    size="sm"
                                    variant="ghost"
                                    :aria-disabled="$loop->last ? 'true' : 'false'"
                                    :class="$loop->last ? 'opacity-40' : ''"
                                    :aria-label="__('templates.move_down', ['name' => $step->name])"
                                />
                            </div>
                        </div>

                        @if ($step->instructions)
                            <flux:text class="text-sm whitespace-pre-line">{{ $step->instructions }}</flux:text>
                        @endif

                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button :href="route('templates.fields', [$template, $step])" size="sm"
                                         variant="ghost" icon="list-bullet">
                                {{ __('templates.manage_fields') }}
                            </flux:button>

                            <flux:text class="text-sm">
                                {{ trans_choice('templates.fields_count', $step->field_definitions_count, ['count' => $step->field_definitions_count]) }}
                            </flux:text>

                            <div class="flex flex-wrap gap-2 sm:ms-auto">
                                <flux:button wire:click="openEditForm('{{ $step->id }}')" size="sm" variant="ghost">
                                    {{ __('templates.edit') }}
                                </flux:button>

                                <flux:button
                                    wire:click="delete('{{ $step->id }}')"
                                    wire:confirm="{{ __('templates.confirm_delete_step', ['name' => $step->name]) }}"
                                    size="sm"
                                    variant="ghost"
                                >
                                    {{ __('templates.delete_step') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:card>
                </li>
            @endforeach
        </ol>
    @endif
</div>
