<?php

use App\Actions\Workflows\SetDefaultWorkflowTemplate;
use App\Exceptions\InactiveTemplateCannotBeDefault;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Elenco e configurazione dei template di workflow.
 *
 * Il flag di predefinito non viene mai scritto da qui: passa dalla Action
 * `SetDefaultWorkflowTemplate`, unico percorso che garantisce l'invariante di un
 * solo predefinito.
 *
 * Ogni operazione che muta lo stato passa da `Gate::authorize`: l'interfaccia
 * nasconde i comandi non disponibili, ma non e quello il controllo.
 */
new class extends Component
{
    /** Template in modifica; `null` quando si sta creando. */
    public ?string $editingId = null;

    public bool $showingForm = false;

    public string $name = '';

    public string $description = '';

    /** Messaggio del rifiuto di un'operazione, con il motivo per esteso. */
    public ?string $operationError = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('workflow_templates', 'name')->ignore($this->editingId),
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
            'name' => __('templates.name'),
            'description' => __('templates.template_description'),
        ];
    }

    /**
     * Template con i conteggi gia caricati: quello degli step decide
     * l'utilizzabilita, quello dei progetti spiega l'impatto di una
     * disattivazione. Una sola query per l'elenco, nessun conteggio per riga.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, WorkflowTemplate>
     */
    #[Computed]
    public function templates()
    {
        return WorkflowTemplate::query()
            ->withCount(['stepDefinitions', 'projects'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function openCreateForm(): void
    {
        Gate::authorize('create', WorkflowTemplate::class);

        $this->reset(['editingId', 'name', 'description', 'operationError']);
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function openEditForm(string $id): void
    {
        $template = WorkflowTemplate::findOrFail($id);

        Gate::authorize('update', $template);

        $this->editingId = $template->id;
        $this->name = $template->name;
        $this->description = (string) $template->description;
        $this->operationError = null;
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
        $template = $this->editingId ? WorkflowTemplate::findOrFail($this->editingId) : null;

        Gate::authorize($template ? 'update' : 'create', $template ?? WorkflowTemplate::class);

        $validated = $this->validate();

        $attributes = [
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
        ];

        if ($template) {
            $template->update($attributes);
        } else {
            WorkflowTemplate::create($attributes + ['is_active' => true, 'is_default' => false]);
        }

        $this->operationError = null;
        unset($this->templates);
        $this->closeForm();
    }

    public function toggleActivation(string $id): void
    {
        $template = WorkflowTemplate::findOrFail($id);

        Gate::authorize('toggleActivation', $template);

        $template->toggleActivation();

        $this->operationError = null;
        unset($this->templates);
    }

    /**
     * Elegge il template proposto ai nuovi progetti.
     *
     * Il rifiuto sul template disattivato viene deciso **prima**
     * dell'autorizzazione — come la cancellazione di un ruolo referenziato in
     * US-002 — cosi che un membro non autorizzato riceva un 403 e non il
     * messaggio di dominio, che maschererebbe un difetto di autorizzazione.
     */
    public function setAsDefault(string $id): void
    {
        $template = WorkflowTemplate::findOrFail($id);

        if (! $template->is_active) {
            Gate::authorize('viewAny', WorkflowTemplate::class);

            $this->operationError = __('templates.default_requires_active');

            return;
        }

        Gate::authorize('setDefault', $template);

        try {
            app(SetDefaultWorkflowTemplate::class)->handle($template);
        } catch (InactiveTemplateCannotBeDefault) {
            /*
             * Il controllo qui sopra legge lo stato di un istante prima: fra
             * quella lettura e la scrittura il template puo essere stato
             * disattivato da qualcun altro. L'Action decide sul dato fresco e
             * rifiuta; senza questa cattura il rifiuto diventerebbe un 500,
             * cioe un errore tecnico al posto di un messaggio comprensibile.
             */
            $this->operationError = __('templates.default_requires_active');

            return;
        }

        $this->operationError = null;
        unset($this->templates);
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('templates.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('templates.description') }}</flux:text>
        </div>

        <flux:button wire:click="openCreateForm" variant="primary" icon="plus">
            {{ __('templates.create_action') }}
        </flux:button>
    </div>

    @if ($operationError)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" aria-live="polite">
            {{ $operationError }}
        </flux:callout>
    @endif

    @if ($showingForm)
        <flux:card class="mb-6 space-y-5">
            <flux:heading size="lg" level="2">
                {{ $editingId ? __('templates.edit_heading') : __('templates.create_heading') }}
            </flux:heading>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" :label="__('templates.name')"
                            :description="__('templates.name_help')" required />

                <flux:textarea wire:model="description" :label="__('templates.template_description')"
                               :description="__('templates.description_help')" rows="3" />

                <div class="flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary">{{ __('templates.save') }}</flux:button>
                    <flux:button type="button" wire:click="closeForm" variant="ghost">
                        {{ __('templates.cancel') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($this->templates->isEmpty())
        <flux:callout icon="information-circle">{{ __('templates.empty') }}</flux:callout>
    @else
        {{-- Elenco di schede e non tabella: a 375 px una tabella con quattro
             colonne e tre comandi per riga imporrebbe lo scorrimento orizzontale. --}}
        <div class="space-y-4">
            @foreach ($this->templates as $template)
                <flux:card :key="$template->id" class="space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading size="lg" level="2">{{ $template->name }}</flux:heading>

                                @if ($template->is_default)
                                    <flux:badge size="sm" icon="star">{{ __('templates.default') }}</flux:badge>
                                @endif
                            </div>

                            @if ($template->description)
                                <flux:text class="mt-1 text-sm">{{ $template->description }}</flux:text>
                            @endif
                        </div>

                        {{-- Stato reso con icona e parola, mai dal solo colore. --}}
                        <span class="inline-flex items-center gap-1.5 text-sm">
                            <flux:icon
                                :name="$template->is_active ? 'check-circle' : 'no-symbol'"
                                variant="mini"
                                class="size-4 shrink-0"
                            />
                            {{ $template->is_active ? __('templates.active') : __('templates.inactive') }}
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <span>{{ trans_choice('templates.steps_count', $template->step_definitions_count, ['count' => $template->step_definitions_count]) }}</span>
                        <span>{{ trans_choice('templates.projects_count', $template->projects_count, ['count' => $template->projects_count]) }}</span>
                    </div>

                    @if ($reason = $template->unusableReason())
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            {{ __($reason) }}
                        </flux:callout>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        <flux:button :href="route('templates.steps', $template)" size="sm" variant="ghost"
                                     icon="queue-list">
                            {{ __('templates.manage_steps') }}
                        </flux:button>

                        <flux:button wire:click="openEditForm('{{ $template->id }}')" size="sm" variant="ghost">
                            {{ __('templates.edit') }}
                        </flux:button>

                        @unless ($template->is_default)
                            <flux:button wire:click="setAsDefault('{{ $template->id }}')" size="sm" variant="ghost">
                                {{ __('templates.set_default') }}
                            </flux:button>
                        @endunless

                        <flux:button
                            wire:click="toggleActivation('{{ $template->id }}')"
                            wire:confirm="{{ $template->is_active
                                ? ($template->is_default
                                    ? __('templates.confirm_deactivate_default', ['name' => $template->name])
                                    : __('templates.confirm_deactivate', ['name' => $template->name]))
                                : __('templates.confirm_activate', ['name' => $template->name]) }}"
                            size="sm"
                            variant="ghost"
                        >
                            {{ $template->is_active ? __('templates.deactivate') : __('templates.activate') }}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:text class="mt-6 text-xs">{{ __('templates.default_explained') }}</flux:text>
    <flux:text class="mt-1 text-xs">{{ __('templates.no_deletion_note') }}</flux:text>
</div>
