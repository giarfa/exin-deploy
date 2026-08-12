<?php

use App\Enums\FieldType;
use App\Models\FieldDefinition;
use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Campi informativi richiesti da uno step per essere chiuso.
 *
 * E la schermata in cui l'enum del tipo diventa visibile: le opzioni sono
 * generate da `FieldType::cases()` e le etichette da `label()`, cosi che un
 * quinto tipo non richieda modifiche alla vista. `Rule::enum` rifiuta lato
 * server un valore fuori dall'enum: non basta che non sia nel menu.
 *
 * Lo step arriva dal binding con `scopeBindings()`: uno step di un altro
 * template non e raggiungibile cambiando identificativo nell'indirizzo.
 */
new class extends Component
{
    /** Template risolto dal binding di rotta. */
    public WorkflowTemplate $template;

    /**
     * Step risolto dal binding di rotta, gia vincolato al template.
     *
     * Si chiama `stepDefinition` e non `step` perche il binding annidato ricava
     * da questo nome la relazione da interrogare sul template (`stepDefinitions`).
     */
    public StepDefinition $stepDefinition;

    /** Campo in modifica; `null` quando si sta creando. */
    public ?string $editingId = null;

    public bool $showingForm = false;

    public string $label = '';

    public string $type = '';

    public bool $isRequired = false;

    public string $helpText = '';

    /** Conferma dell'ultimo spostamento, annunciata a chi usa uno screen reader. */
    public ?string $feedback = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(FieldType::class)],
            'isRequired' => ['boolean'],
            'helpText' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'label' => __('templates.field_label'),
            'type' => __('templates.field_type'),
            'isRequired' => __('templates.field_required'),
            'helpText' => __('templates.field_help_text'),
        ];
    }

    /**
     * Campi dello step, gia in ordine di compilazione. Una sola query.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, FieldDefinition>
     */
    #[Computed]
    public function fields()
    {
        return $this->stepDefinition->fieldDefinitions()->get();
    }

    /**
     * @return array<int, FieldType>
     */
    #[Computed]
    public function types(): array
    {
        return FieldType::cases();
    }

    public function openCreateForm(): void
    {
        Gate::authorize('manageSteps', $this->template);

        $this->reset(['editingId', 'label', 'type', 'isRequired', 'helpText', 'feedback']);
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function openEditForm(string $id): void
    {
        Gate::authorize('manageSteps', $this->template);

        $field = $this->findField($id);

        $this->editingId = $field->id;
        $this->label = $field->label;
        $this->type = $field->type->value;
        $this->isRequired = $field->is_required;
        $this->helpText = (string) $field->help_text;
        $this->feedback = null;
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function closeForm(): void
    {
        $this->showingForm = false;
        $this->reset(['editingId', 'label', 'type', 'isRequired', 'helpText']);
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manageSteps', $this->template);

        $validated = $this->validate();

        $attributes = [
            'label' => $validated['label'],
            'type' => $validated['type'],
            'is_required' => $validated['isRequired'],
            'help_text' => $validated['helpText'] !== '' ? $validated['helpText'] : null,
        ];

        if ($this->editingId) {
            $this->findField($this->editingId)->update($attributes);
        } else {
            $field = $this->stepDefinition->fieldDefinitions()->make($attributes);
            $field->position = $field->nextPosition();
            $field->save();
        }

        unset($this->fields);
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

        $this->findField($id)->deleteAndResequence();

        $this->feedback = null;
        unset($this->fields);
    }

    private function move(string $id, bool $up): void
    {
        Gate::authorize('manageSteps', $this->template);

        $field = $this->findField($id);

        $up ? $field->moveUp() : $field->moveDown();

        $this->feedback = __('templates.moved', [
            'name' => $field->label,
            'position' => $field->fresh()->position,
        ]);

        unset($this->fields);
    }

    /**
     * Il campo viene cercato **dentro** lo step della rotta: un campo di un altro
     * step non e raggiungibile passandone l'identificativo a un'azione.
     */
    private function findField(string $id): FieldDefinition
    {
        return $this->stepDefinition->fieldDefinitions()->findOrFail($id);
    }
};
?>

<div>
    {{-- Briciole di navigazione: e la pagina piu profonda del prodotto, e senza
         di esse non si sa piu a quale template appartiene lo step. --}}
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('templates.index')">
            {{ __('templates.heading') }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('templates.steps', $template)">
            {{ $template->name }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $stepDefinition->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">
                {{ __('templates.fields_heading', ['step' => $stepDefinition->name]) }}
            </flux:heading>
            <flux:text class="mt-1">{{ __('templates.fields_description') }}</flux:text>
        </div>

        <flux:button wire:click="openCreateForm" variant="primary" icon="plus">
            {{ __('templates.field_create_action') }}
        </flux:button>
    </div>

    <div aria-live="polite" class="sr-only">{{ $feedback }}</div>

    @if ($feedback)
        <flux:callout variant="success" icon="check-circle" class="mb-6" aria-hidden="true">
            {{ $feedback }}
        </flux:callout>
    @endif

    @if ($showingForm)
        <flux:card class="mb-6 space-y-5">
            <flux:heading size="lg" level="2">
                {{ $editingId ? __('templates.field_edit_heading') : __('templates.field_create_heading') }}
            </flux:heading>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="label" :label="__('templates.field_label')"
                            :description="__('templates.field_label_help')" required />

                {{-- Le opzioni derivano dall'enum: un quinto tipo non richiederebbe
                     di toccare questa vista. --}}
                <flux:select wire:model="type" :label="__('templates.field_type')"
                             :description="__('templates.field_type_help')" required>
                    <flux:select.option value="">—</flux:select.option>

                    @foreach ($this->types as $fieldType)
                        <flux:select.option value="{{ $fieldType->value }}">
                            {{ $fieldType->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:switch wire:model="isRequired" :label="__('templates.field_required')"
                             :description="__('templates.field_required_help')" />

                <flux:input wire:model="helpText" :label="__('templates.field_help_text')"
                            :description="__('templates.field_help_text_help')" />

                <div class="flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary">{{ __('templates.save') }}</flux:button>
                    <flux:button type="button" wire:click="closeForm" variant="ghost">
                        {{ __('templates.cancel') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($this->fields->isEmpty())
        <flux:callout icon="information-circle">{{ __('templates.fields_empty') }}</flux:callout>
    @else
        {{-- Elenco di schede: a 375 px una tabella con etichetta, tipo,
             obbligatorieta, aiuto e quattro comandi non ci sta. --}}
        <ol class="space-y-4">
            @foreach ($this->fields as $field)
                <li>
                    <flux:card class="space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <flux:text class="text-xs">
                                    {{ __('templates.step_position', ['position' => $field->position]) }}
                                </flux:text>

                                <flux:heading size="lg" level="2">{{ $field->label }}</flux:heading>

                                {{-- Tipo e obbligatorieta con icona e parola, mai col solo colore. --}}
                                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                    <span class="inline-flex items-center gap-1.5">
                                        <flux:icon name="tag" variant="mini" class="size-4 shrink-0" />
                                        {{ $field->type->label() }}
                                    </span>

                                    <span class="inline-flex items-center gap-1.5">
                                        <flux:icon
                                            :name="$field->is_required ? 'exclamation-circle' : 'minus-circle'"
                                            variant="mini"
                                            class="size-4 shrink-0"
                                        />
                                        {{ $field->is_required ? __('templates.required') : __('templates.optional') }}
                                    </span>
                                </div>

                                @if ($field->help_text)
                                    <flux:text class="mt-1 text-xs">{{ $field->help_text }}</flux:text>
                                @endif
                            </div>

                            <div class="flex shrink-0 gap-1">
                                <flux:button
                                    wire:click="moveUp('{{ $field->id }}')"
                                    icon="arrow-up"
                                    size="sm"
                                    variant="ghost"
                                    :aria-disabled="$loop->first ? 'true' : 'false'"
                                    :class="$loop->first ? 'opacity-40' : ''"
                                    :aria-label="__('templates.move_up', ['name' => $field->label])"
                                />

                                <flux:button
                                    wire:click="moveDown('{{ $field->id }}')"
                                    icon="arrow-down"
                                    size="sm"
                                    variant="ghost"
                                    :aria-disabled="$loop->last ? 'true' : 'false'"
                                    :class="$loop->last ? 'opacity-40' : ''"
                                    :aria-label="__('templates.move_down', ['name' => $field->label])"
                                />
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <flux:button wire:click="openEditForm('{{ $field->id }}')" size="sm" variant="ghost">
                                {{ __('templates.edit') }}
                            </flux:button>

                            <flux:button
                                wire:click="delete('{{ $field->id }}')"
                                wire:confirm="{{ __('templates.confirm_delete_field', ['label' => $field->label]) }}"
                                size="sm"
                                variant="ghost"
                            >
                                {{ __('templates.delete_field') }}
                            </flux:button>
                        </div>
                    </flux:card>
                </li>
            @endforeach
        </ol>
    @endif

    <flux:text class="mt-6 text-xs">{{ __('templates.field_required_help') }}</flux:text>
</div>
