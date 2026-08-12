<?php

use App\Actions\Projects\CreateProject;
use App\Models\Project;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Elenco e configurazione dei progetti.
 *
 * La creazione passa dalla Action `CreateProject`, che precompila la mappatura
 * predefinita del team dentro una sola transazione: e il comportamento che
 * elimina la riconfigurazione manuale a ogni nuovo progetto.
 */
new class extends Component
{
    /** Progetto in modifica; `null` quando si sta creando. */
    public ?string $editingId = null;

    public bool $showingForm = false;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    /** Template scelto; stringa vuota significa "nessun template". */
    public string $workflowTemplateId = '';

    /** Esito della creazione: quanti ruoli non e stato possibile precompilare. */
    public ?int $skippedRoles = null;

    /** Nome del template applicato alla creazione, `null` se nessuno. */
    public ?string $appliedTemplate = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            // Validato contro le **sole opzioni in elenco** e non contro l'intera
            // tabella: un template disattivato e non gia associato a questo
            // progetto non e scegliibile nemmeno indicandone l'identificativo.
            'workflowTemplateId' => ['nullable', 'string', Rule::in($this->selectableTemplates->pluck('id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'name' => __('projects.name'),
            'slug' => __('projects.slug'),
            'description' => __('projects.project_description'),
            'workflowTemplateId' => __('projects.workflow_template'),
        ];
    }

    /**
     * Template proponibili: quelli attivi, piu quello attualmente associato al
     * progetto in modifica anche se disattivato — nasconderlo mostrerebbe
     * un'associazione diversa da quella reale.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, WorkflowTemplate>
     */
    #[Computed]
    public function selectableTemplates()
    {
        $current = $this->editingId
            ? Project::whereKey($this->editingId)->value('workflow_template_id')
            : null;

        return WorkflowTemplate::query()
            ->select(['id', 'name', 'is_active'])
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current))
            ->orderBy('name')
            ->get();
    }

    /**
     * Lo slug segue il nome finche l'utente non lo tocca: sotto e comunque
     * validato, quindi la proposta e una comodita e non una scorciatoia.
     */
    public function updatedName(string $value): void
    {
        if (! $this->editingId) {
            $this->slug = Str::slug($value);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects()
    {
        // Eager loading obbligatorio: la segnalazione dei ruoli scoperti legge
        // template, step e assegnazioni per ogni riga. Senza queste relazioni
        // precaricate l'elenco farebbe query proporzionali al numero di progetti.
        return Project::query()
            ->withCount('assignments')
            ->with([
                'workflowTemplate:id,name,is_active',
                'workflowTemplate.stepDefinitions:id,workflow_template_id,role_id',
                'workflowTemplate.stepDefinitions.role:id,name',
                'assignments:id,project_id,role_id',
            ])
            ->orderBy('name')
            ->get();
    }

    public function openCreateForm(): void
    {
        Gate::authorize('create', Project::class);

        $this->reset(['editingId', 'name', 'slug', 'description', 'skippedRoles', 'appliedTemplate']);
        $this->resetValidation();

        // Il predefinito e il valore **proposto**, non un legame: resta sostituibile.
        $this->workflowTemplateId = (string) WorkflowTemplate::query()
            ->active()
            ->where('is_default', true)
            ->value('id');

        $this->showingForm = true;
    }

    public function openEditForm(string $id): void
    {
        $project = Project::findOrFail($id);

        Gate::authorize('update', $project);

        $this->editingId = $project->id;
        $this->name = $project->name;
        $this->slug = $project->slug;
        $this->description = (string) $project->description;
        $this->workflowTemplateId = (string) $project->workflow_template_id;
        $this->skippedRoles = null;
        $this->appliedTemplate = null;
        $this->resetValidation();
        $this->showingForm = true;
    }

    public function closeForm(): void
    {
        $this->showingForm = false;
        $this->reset(['editingId', 'name', 'slug', 'description', 'workflowTemplateId']);
        $this->resetValidation();
    }

    public function save(CreateProject $createProject): void
    {
        $project = $this->editingId ? Project::findOrFail($this->editingId) : null;

        Gate::authorize($project ? 'update' : 'create', $project ?? Project::class);

        $validated = $this->validate();

        $attributes = [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'workflow_template_id' => $validated['workflowTemplateId'] !== '' ? $validated['workflowTemplateId'] : null,
        ];

        if ($project) {
            $project->update($attributes);
            $this->skippedRoles = null;
            $this->appliedTemplate = null;
        } else {
            $result = $createProject->handle($attributes + ['is_active' => true]);

            $this->skippedRoles = $result['skipped'];
            $this->appliedTemplate = $result['template']?->name;
        }

        unset($this->projects, $this->selectableTemplates);
        $this->closeForm();
    }

    public function toggleActivation(string $id): void
    {
        $project = Project::findOrFail($id);

        Gate::authorize('toggleActivation', $project);

        $project->update(['is_active' => ! $project->is_active]);

        unset($this->projects);
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('projects.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('projects.description') }}</flux:text>
        </div>

        <flux:button wire:click="openCreateForm" variant="primary" icon="plus">
            {{ __('projects.create_action') }}
        </flux:button>
    </div>

    @if (! is_null($skippedRoles))
        <flux:callout
            :variant="$skippedRoles > 0 ? 'warning' : 'success'"
            :icon="$skippedRoles > 0 ? 'exclamation-triangle' : 'check-circle'"
            class="mb-6"
            aria-live="polite"
        >
            @if ($skippedRoles > 0)
                {{ trans_choice('projects.created_with_gaps', $skippedRoles, ['count' => $skippedRoles]) }}
            @else
                {{ __('projects.created_with_defaults') }}
            @endif

            <div class="mt-1">
                @if ($appliedTemplate)
                    {{ __('projects.created_with_template', ['template' => $appliedTemplate]) }}
                @else
                    {{ __('projects.created_without_template') }}
                @endif
            </div>
        </flux:callout>
    @endif

    @if ($showingForm)
        <flux:card class="mb-6 space-y-5">
            <flux:heading size="lg" level="2">
                {{ $editingId ? __('projects.edit_heading') : __('projects.create_heading') }}
            </flux:heading>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model.blur="name" :label="__('projects.name')" required />

                <flux:input wire:model="slug" :label="__('projects.slug')"
                            :description="__('projects.slug_help')" required />

                <flux:textarea wire:model="description" :label="__('projects.project_description')" rows="3" />

                <flux:select wire:model="workflowTemplateId" :label="__('projects.workflow_template')"
                             :description="__('projects.workflow_template_help')">
                    <flux:select.option value="">{{ __('projects.no_template_option') }}</flux:select.option>

                    @foreach ($this->selectableTemplates as $template)
                        <flux:select.option value="{{ $template->id }}">
                            {{ $template->name }}{{ $template->is_active ? '' : ' — '.__('projects.inactive') }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary">{{ __('projects.save') }}</flux:button>
                    <flux:button type="button" wire:click="closeForm" variant="ghost">
                        {{ __('projects.cancel') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($this->projects->isEmpty())
        <flux:callout icon="information-circle">{{ __('projects.empty') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('projects.name') }}</flux:table.column>
                <flux:table.column>{{ __('projects.status') }}</flux:table.column>
                <flux:table.column>{{ __('projects.assignments') }}</flux:table.column>
                <flux:table.column class="text-end">{{ __('projects.actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->projects as $project)
                    <flux:table.row :key="$project->id">
                        {{-- Template e ruoli scoperti stanno dentro le celle esistenti e non
                             in due colonne nuove: a 375 px una tabella a sei colonne
                             obbligherebbe allo scorrimento orizzontale, e la segnalazione
                             che deve farsi notare finirebbe fuori schermo. --}}
                        <flux:table.cell variant="strong">
                            {{ $project->name }}
                            <flux:text class="mt-0.5 font-mono text-xs">{{ $project->slug }}</flux:text>

                            <flux:text class="mt-1 inline-flex items-center gap-1.5 text-xs">
                                <flux:icon name="queue-list" variant="mini" class="size-4 shrink-0" />
                                {{ $project->workflowTemplate?->name ?? __('projects.no_template') }}
                            </flux:text>
                        </flux:table.cell>

                        <flux:table.cell>
                            {{-- Stato reso con icona e parola, mai dal solo colore. --}}
                            <span class="inline-flex items-center gap-1.5 text-sm">
                                <flux:icon
                                    :name="$project->is_active ? 'check-circle' : 'no-symbol'"
                                    variant="mini"
                                    class="size-4 shrink-0"
                                />
                                {{ $project->is_active ? __('projects.active') : __('projects.inactive') }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell class="text-sm">
                            {{ trans_choice('projects.assignments_count', $project->assignments_count, ['count' => $project->assignments_count]) }}

                            @php($uncovered = $project->uncoveredRoles())

                            @if ($uncovered->isNotEmpty())
                                <flux:text class="mt-1 inline-flex items-center gap-1.5 text-xs">
                                    <flux:icon name="exclamation-triangle" variant="mini" class="size-4 shrink-0" />
                                    {{ trans_choice('projects.uncovered_roles_badge', $uncovered->count(), ['count' => $uncovered->count()]) }}
                                </flux:text>
                            @endif

                            @if ($project->workflowTemplate && $reason = $project->workflowTemplate->unusableReason())
                                <flux:text class="mt-1 inline-flex items-center gap-1.5 text-xs">
                                    <flux:icon name="no-symbol" variant="mini" class="size-4 shrink-0" />
                                    {{ __($reason) }}
                                </flux:text>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-end whitespace-nowrap">
                            <flux:button :href="route('projects.assignments', $project)" size="sm" variant="ghost">
                                {{ __('projects.manage_assignments') }}
                            </flux:button>

                            <flux:button wire:click="openEditForm('{{ $project->id }}')" size="sm" variant="ghost">
                                {{ __('projects.edit') }}
                            </flux:button>

                            <flux:button
                                wire:click="toggleActivation('{{ $project->id }}')"
                                wire:confirm="{{ $project->is_active ? __('projects.confirm_deactivate', ['name' => $project->name]) : __('projects.confirm_activate', ['name' => $project->name]) }}"
                                size="sm"
                                variant="ghost"
                            >
                                {{ $project->is_active ? __('projects.deactivate') : __('projects.activate') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:text class="mt-4 text-xs">{{ __('projects.no_deletion_note') }}</flux:text>
</div>
