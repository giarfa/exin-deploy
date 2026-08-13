<?php

use App\Actions\Releases\CloseStep;
use App\Actions\Releases\RecordUnauthorizedStepAttempt;
use App\Actions\Releases\SaveStepValues;
use App\Enums\FieldType;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\StepAlreadyClosed;
use App\Exceptions\StepIsNotOpen;
use App\Exceptions\StepValuesAreInvalid;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Compilazione e chiusura di uno step di una release avviata.
 *
 * La schermata rende tre stati diversi dello stesso step: **attivo** con il form,
 * **completato** in sola lettura, **bloccato** con l'indicazione di chi si sta
 * aspettando. Sono tre pagine diverse solo in apparenza: chi arriva da un
 * collegamento salvato non sa in quale stato lo trovera, e una pagina che rifiuta
 * senza spiegare cosa sta succedendo lo lascerebbe senza sapere cosa fare.
 *
 * L'autorizzazione passa **sempre** da `authorizeOrRecord()`, sia al montaggio sia
 * su ogni azione: le azioni Livewire non ripassano dal middleware della rotta, e la
 * rotta — deliberatamente — non ha un `->can()` (vedi il commento in
 * `routes/web.php`). Ogni rifiuto viene registrato prima di essere emesso.
 *
 * I rifiuti dell'Action sono catturati e resi come messaggi, mai come 500: stesso
 * trattamento adottato da `releases.start` dopo la revisione di US-003 — fra la
 * lettura della pagina e l'invio lo stato puo essere cambiato, e quello non e un
 * errore tecnico.
 */
new class extends Component
{
    /** Step risolto dal binding di rotta. */
    public ReleaseStep $releaseStep;

    /**
     * Valori compilati, indicizzati per identificativo di campo: le stesse chiavi
     * con cui `ReleaseStep::closingRules()` indicizza le regole, cosi che ogni
     * errore torni sul campo che lo ha prodotto.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /** Motivo del rifiuto dell'ultima operazione, quando non riguarda un campo. */
    public ?string $operationError = null;

    /** Conferma dell'ultima bozza salvata. */
    public bool $saved = false;

    /** Chiusura avvenuta in questa sessione di pagina. */
    public bool $closed = false;

    public function mount(): void
    {
        $this->authorizeOrRecord('view');

        /*
         * Un solo caricamento con tutto cio che la pagina legge: campi dello step,
         * release, progetto e responsabile. Senza, il form farebbe una query per
         * campo e l'intestazione una per riferimento (vedi
         * `CloseStepQueryBudgetTest`).
         *
         * Solo snapshot: nessun percorso risale a `step_definitions` per sapere
         * cosa questo step chiede — la copia congelata e la sola fonte di verita.
         */
        $this->releaseStep->load(['fields', 'release.project', 'assignedUser']);

        $this->values = $this->releaseStep->fields
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => $field->type === FieldType::Confirmation
                    ? $field->value === '1'
                    : (string) $field->value,
            ])
            ->all();
    }

    /**
     * Campi da compilare, nell'ordine congelato.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ReleaseStepField>
     */
    #[Computed]
    public function fields()
    {
        return $this->releaseStep->fields;
    }

    /**
     * Numero di step della release, per il "step 2 di 5" dell'intestazione.
     */
    #[Computed]
    public function chainLength(): int
    {
        return $this->releaseStep->release->steps()->count();
    }

    /**
     * Step che ricevera il flusso alla chiusura; `null` sull'ultimo della catena.
     *
     * La regola vive su `ReleaseStep::nextStep()`: una seconda copia qui
     * divergerebbe da quella che l'Action usa per avanzare davvero.
     */
    #[Computed]
    public function nextStep(): ?ReleaseStep
    {
        $next = $this->releaseStep->nextStep();

        $next?->loadMissing('assignedUser:id,name');

        return $next;
    }

    /**
     * Step attivo della release, mostrato quando questo e ancora bloccato: sapere
     * "non e il tuo turno" senza sapere di chi lo sia lascia senza azioni.
     */
    #[Computed]
    public function awaitedStep(): ?ReleaseStep
    {
        return $this->releaseStep->release->steps()
            ->where('status', ReleaseStepStatus::Active->value)
            ->with('assignedUser:id,name')
            ->first();
    }

    /**
     * Se la pagina puo offrire il form: la decisione e della Policy, non dello
     * stato letto qui.
     */
    #[Computed]
    public function canFill(): bool
    {
        return Gate::allows('fill', $this->releaseStep);
    }

    public function save(SaveStepValues $saveStepValues): void
    {
        $this->authorizeOrRecord('fill');

        $this->operationError = null;
        $this->saved = false;
        $this->resetValidation();

        try {
            $saveStepValues->handle($this->releaseStep, $this->values, auth()->user());
        } catch (StepValuesAreInvalid $refused) {
            $this->reportFieldErrors($refused);

            return;
        } catch (StepIsNotOpen $refused) {
            $this->refuse(__($refused->reasonKey));

            return;
        }

        $this->saved = true;
        $this->reload();
    }

    public function close(CloseStep $closeStep): void
    {
        $this->authorizeOrRecord('close');

        $this->operationError = null;
        $this->saved = false;
        $this->resetValidation();

        try {
            $closeStep->handle($this->releaseStep, $this->values, auth()->user());
        } catch (StepValuesAreInvalid $refused) {
            $this->reportFieldErrors($refused);

            return;
        } catch (StepIsNotOpen $refused) {
            $this->refuse(__($refused->reasonKey));

            return;
        } catch (StepAlreadyClosed $refused) {
            // Dal `reasonKey` e non da una chiave scritta a mano: il doppio invio
            // dell'ultimo step ha concluso la release, e dirgli che "il flusso e
            // passato al responsabile successivo" sarebbe falso.
            $this->refuse(__($refused->reasonKey));

            return;
        }

        $this->closed = true;
        $this->reload();
    }

    /**
     * Riporta gli errori di validazione sui campi che li hanno prodotti e annuncia
     * il riepilogo.
     *
     * Le chiavi del bag sono gli identificativi dei campi: diventano `values.{id}`,
     * cioe il percorso della proprieta a cui il controllo e legato, che e quello che
     * il markup interroga per collegare l'errore al campo.
     */
    private function reportFieldErrors(StepValuesAreInvalid $refused): void
    {
        foreach ($refused->errors->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('values.'.$field, $message);
            }
        }

        // Il riepilogo si prende il focus: dopo un rifiuto, chi usa la tastiera o
        // uno screen reader resterebbe altrimenti in fondo alla pagina, senza sapere
        // che qualcosa e comparso in cima.
        $this->dispatch('step-refused');
    }

    /**
     * Registra il rifiuto e riallinea lo stato mostrato.
     */
    private function refuse(string $message): void
    {
        $this->operationError = $message;
        $this->reload();
    }

    /**
     * Rilegge lo step e le sue relazioni dopo un'operazione.
     *
     * `refresh()` ricarica anche le relazioni gia caricate: un `load()` qui
     * rifarebbe le stesse query una seconda volta.
     */
    private function reload(): void
    {
        $this->releaseStep->refresh();

        $this->values = $this->releaseStep->fields
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => $field->type === FieldType::Confirmation
                    ? $field->value === '1'
                    : (string) $field->value,
            ])
            ->all();

        unset($this->fields, $this->nextStep, $this->awaitedStep, $this->canFill, $this->chainLength);
    }

    /**
     * Unico punto di autorizzazione: registra il tentativo negato e poi rifiuta.
     *
     * L'ordine conta. Il tracciamento e un criterio di accettazione, quindi deve
     * avvenire **prima** del rifiuto e non dentro un gestore di eccezioni: un
     * `Gate::authorize()` interromperebbe l'esecuzione, e la riga di registro non
     * verrebbe mai scritta. E la ragione per cui la rotta non porta un `->can()`.
     */
    private function authorizeOrRecord(string $ability): void
    {
        if (Gate::allows($ability, $this->releaseStep)) {
            return;
        }

        app(RecordUnauthorizedStepAttempt::class)->handle(
            $this->releaseStep,
            auth()->user(),
            $ability
        );

        abort(403);
    }
};
?>

<div>
    <flux:button :href="route('home')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('releases.step_back_home') }}
    </flux:button>

    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ $releaseStep->name }}</flux:heading>

        <flux:text class="mt-1">
            {{ __('releases.step_context', [
                'project' => $releaseStep->release->project->name,
                'release' => $releaseStep->release->label,
                'position' => $releaseStep->position,
                'total' => $this->chainLength,
            ]) }}
            —
            @if ($releaseStep->assigned_user_id === auth()->id())
                {{ __('releases.step_you_are_responsible', ['role' => $releaseStep->role_name]) }}
            @else
                {{ __('releases.step_responsible_is', [
                    'name' => $releaseStep->assignedUser->name,
                    'role' => $releaseStep->role_name,
                ]) }}
            @endif
        </flux:text>
    </div>

    @if ($closed)
        {{-- Un riquadro solo, con due esiti diversi: la catena prosegue e il flusso
             passa a qualcuno, oppure finisce e il rilascio e consegnato. Nessun
             comando in nessuno dei due casi — la riapertura di uno step non e
             prevista (FR-019) e la schermata non deve lasciar credere il contrario. --}}
        <flux:callout variant="success" icon="check-circle" class="mb-6" aria-live="polite">
            @if ($this->nextStep)
                {{ __('releases.step_closed_heading') }}

                <div class="mt-1">
                    {{ __('releases.step_closed_handed_over', [
                        'name' => $this->nextStep->assignedUser->name,
                        'step' => $this->nextStep->name,
                    ]) }}
                </div>
            @else
                {{ __('releases.step_release_completed_heading') }}

                <div class="mt-1">
                    {{ __('releases.step_release_completed_announced', [
                        'release' => $releaseStep->release->label,
                        'date' => $releaseStep->release->completed_at?->format('d/m/Y H:i'),
                    ]) }}
                </div>
            @endif
        </flux:callout>
    @endif

    @if ($saved)
        <flux:callout variant="secondary" icon="bookmark" class="mb-6" aria-live="polite">
            {{ __('releases.step_saved_notice') }}
        </flux:callout>
    @endif

    @if ($operationError)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" aria-live="polite">
            {{ $operationError }}
        </flux:callout>
    @endif

    {{--
        Riepilogo degli errori, in cima e con `role="alert"`: e il contratto fissato
        dal mockup. Prende il focus dopo un rifiuto (`step-refused`), perche
        altrimenti chi usa tastiera o screen reader resterebbe in fondo alla pagina
        senza sapere che qualcosa e comparso sopra. I collegamenti portano al campo.
    --}}
    @if ($errors->any() && $this->canFill)
        <div role="alert" tabindex="-1" x-data x-on:step-refused.window="$el.focus()"
             class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-500/30 dark:bg-red-500/10">
            <div class="flex items-start gap-3">
                <flux:icon name="exclamation-triangle" variant="mini"
                           class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" />

                <div>
                    <flux:heading size="lg" level="2">{{ __('releases.step_errors_heading') }}</flux:heading>

                    <flux:text class="mt-1 text-sm">
                        {{ trans_choice('releases.step_errors_intro', $errors->count(), ['count' => $errors->count()]) }}
                    </flux:text>

                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($this->fields as $field)
                            @error('values.'.$field->id)
                                <li>
                                    <flux:link href="#campo-{{ $field->id }}">{{ $field->label }}</flux:link>
                                    — {{ $message }}
                                </li>
                            @enderror
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    @if ($releaseStep->status === ReleaseStepStatus::Blocked)
        {{-- Nessun form: e comunque la Policy a rifiutare l'azione, ma offrire i
             campi a chi non puo chiudere sarebbe una promessa che la pagina non
             mantiene. --}}
        <flux:card class="space-y-3">
            <flux:heading size="lg" level="2">{{ __('releases.step_blocked_heading') }}</flux:heading>

            <flux:text class="text-sm">
                @if ($this->awaitedStep)
                    {{ __('releases.step_blocked_waiting', [
                        'position' => $this->awaitedStep->position,
                        'step' => $this->awaitedStep->name,
                        'name' => $this->awaitedStep->assignedUser->name,
                    ]) }}
                @else
                    {{ __('releases.step_blocked_waiting_unknown') }}
                @endif
            </flux:text>

            <x-releases.step-instructions :step="$releaseStep" />
        </flux:card>
    @elseif ($releaseStep->status === ReleaseStepStatus::Completed)
        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('releases.step_completed_heading') }}</flux:heading>

            <flux:text class="text-sm">
                {{ __('releases.step_completed_explained', [
                    'name' => $releaseStep->completedBy?->name ?? $releaseStep->assignedUser->name,
                    'date' => $releaseStep->completed_at?->format('d/m/Y H:i'),
                ]) }}
            </flux:text>

            @if ($releaseStep->release->status === ReleaseStatus::Completed)
                {{-- Lo stato del rilascio accanto a quello del passaggio: chi arriva
                     da un collegamento salvato deve capire che e chiuso il rilascio
                     intero, non soltanto il proprio step. --}}
                <flux:text class="text-sm">
                    {{ __('releases.step_release_completed_notice', [
                        'release' => $releaseStep->release->label,
                        'date' => $releaseStep->release->completed_at?->format('d/m/Y H:i'),
                    ]) }}
                </flux:text>
            @endif

            <flux:separator variant="subtle" />

            <flux:heading size="lg" level="3">{{ __('releases.step_values_heading') }}</flux:heading>

            {{-- Lista di definizione e non tabella: a 375 px due colonne di testo
                 lungo obbligherebbero allo scorrimento orizzontale. --}}
            <dl class="space-y-4">
                @foreach ($this->fields as $field)
                    <div>
                        <dt class="text-sm font-medium text-zinc-800 dark:text-white">{{ $field->label }}</dt>

                        <dd class="mt-0.5 text-sm break-words text-zinc-500 dark:text-white/70">
                            @if ($field->value === null)
                                {{ __('releases.step_value_not_provided') }}
                            @elseif ($field->type === FieldType::Confirmation)
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon name="check-circle" variant="mini" class="size-4 shrink-0" />
                                    {{ __('releases.step_value_confirmed') }}
                                </span>
                            @elseif ($field->type === FieldType::Link && Str::startsWith($field->value, ['http://', 'https://']))
                                {{--
                                    Lo schema viene verificato **anche** qui, dove il
                                    valore diventa un collegamento cliccabile:
                                    `WellFormedLink` lo garantisce in scrittura, ma
                                    una riga arrivata da un import o da una
                                    correzione a mano sul database non passa da
                                    quella regola, e un `javascript:` reso come href
                                    sarebbe una superficie offerta a chi consulta lo
                                    storico. Un valore non conforme resta leggibile
                                    come testo.

                                    `rel` esplicito: i browser recenti implicano
                                    `noopener` su `target="_blank"`, ma dichiararlo
                                    non dipende dalla versione di chi legge.
                                --}}
                                <flux:link :href="$field->value" external rel="noopener noreferrer">{{ $field->value }}</flux:link>
                            @else
                                {{ $field->value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </flux:card>
    @else
        <flux:card class="space-y-5">
            <x-releases.step-instructions :step="$releaseStep" :next="$this->nextStep" />

            <flux:separator variant="subtle" />

            {{--
                `novalidate` come nel mockup, e non e una rinuncia: un campo di tipo
                link e reso con `type="url"` per avere la tastiera giusta sul
                telefono, ma la validazione nativa del browser bloccherebbe l'invio
                **prima** che l'evento arrivi a Livewire. Il rifiuto sarebbe un
                fumetto di sistema che dice "inserisci un URL", al posto del
                messaggio che dice cosa correggere. La validazione che conta e
                comunque quella lato server.
            --}}
            <form wire:submit="close" class="space-y-6" novalidate>
                @foreach ($this->fields as $field)
                    @php
                        $path = 'values.'.$field->id;
                        $controlId = 'campo-'.$field->id;
                        $errorId = 'errore-'.$field->id;
                        $helpId = 'aiuto-'.$field->id;
                        $invalid = $errors->has($path);

                        /*
                         * `aria-describedby` punta a errore **e** testo di aiuto:
                         * l'aiuto spiega cosa scrivere e l'errore cosa correggere,
                         * e annunciarne uno solo perderebbe metà dell'informazione.
                         */
                        $describedBy = collect([$invalid ? $errorId : null, $field->help_text ? $helpId : null])
                            ->filter()
                            ->implode(' ');
                    @endphp

                    <flux:field wire:key="{{ $field->id }}"
                                :variant="$field->type === FieldType::Confirmation ? 'inline' : 'block'">
                        @if ($field->type === FieldType::Confirmation)
                            <flux:checkbox wire:model="{{ $path }}" :id="$controlId"
                                           :aria-describedby="$describedBy ?: null"
                                           :aria-invalid="$invalid ? 'true' : null" />
                        @endif

                        <flux:label>
                            {{ $field->label }}

                            @if ($field->is_required)
                                {{-- L'obbligatorieta e detta a parole e non dal solo
                                     asterisco: un simbolo non si annuncia. --}}
                                <span aria-hidden="true" class="ms-0.5 text-red-500 dark:text-red-400">*</span>
                                <span class="sr-only">{{ __('releases.step_required_marker') }}</span>
                            @endif
                        </flux:label>

                        @if ($field->type === FieldType::ShortText)
                            <flux:input wire:model="{{ $path }}" :id="$controlId" maxlength="255"
                                        :aria-describedby="$describedBy ?: null"
                                        :aria-invalid="$invalid ? 'true' : null"
                                        :invalid="$invalid" />
                        @elseif ($field->type === FieldType::LongText)
                            <flux:textarea wire:model="{{ $path }}" :id="$controlId" rows="4" maxlength="5000"
                                           :aria-describedby="$describedBy ?: null"
                                           :aria-invalid="$invalid ? 'true' : null"
                                           :invalid="$invalid" />
                        @elseif ($field->type === FieldType::Link)
                            <flux:input wire:model="{{ $path }}" :id="$controlId" type="url" maxlength="2048"
                                        :aria-describedby="$describedBy ?: null"
                                        :aria-invalid="$invalid ? 'true' : null"
                                        :invalid="$invalid" />
                        @endif

                        @if ($invalid)
                            {{-- Paragrafo semplice e non `flux:error`: quello porta
                                 un proprio `role="alert"`, e con il riepilogo in
                                 cima lo stesso messaggio verrebbe annunciato due
                                 volte. Qui il riferimento resta, l'annuncio no. --}}
                            <p id="{{ $errorId }}"
                               class="mt-2 flex items-start gap-1.5 text-sm font-medium text-red-600 dark:text-red-400">
                                <flux:icon name="exclamation-triangle" variant="mini" class="mt-0.5 size-4 shrink-0" />
                                {{ $errors->first($path) }}
                            </p>
                        @endif

                        @if ($field->help_text)
                            <flux:description :id="$helpId">{{ $field->help_text }}</flux:description>
                        @endif

                        @unless ($field->is_required)
                            <flux:description>{{ __('releases.step_optional_hint') }}</flux:description>
                        @endunless
                    </flux:field>
                @endforeach

                {{-- Azioni impilate a piena larghezza sotto la soglia, in linea
                     sopra: e la soglia unica a 1024 px (`max-lg:`) gestita da Flux,
                     e il vincolo permanente n.4 del README vieta di introdurne una
                     seconda. Target touch di 44 px sotto la stessa soglia. --}}
                <div class="flex flex-col gap-3 max-lg:*:min-h-11 max-lg:*:w-full lg:flex-row lg:items-center">
                    <flux:button type="submit" variant="primary" icon="check-circle"
                                 wire:loading.attr="disabled" wire:target="close">
                        {{ __('releases.step_close_action') }}
                    </flux:button>

                    <flux:button type="button" wire:click="save" variant="outline"
                                 wire:loading.attr="disabled" wire:target="save">
                        {{ __('releases.step_save_action') }}
                    </flux:button>
                </div>

                <flux:text class="text-xs">{{ __('releases.step_closing_is_final') }}</flux:text>
            </form>
        </flux:card>
    @endif
</div>
