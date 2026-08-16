<?php

namespace App\Models;

use Database\Factories\WorkflowTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template di workflow: la forma riutilizzabile del processo di rilascio, fatta
 * di step ordinati con un ruolo responsabile e i campi da fornire.
 *
 * E **definizione**, non istanza. All'avvio di una release (US-004) step e campi
 * vengono copiati in uno snapshot, e da quel momento l'esecuzione legge soltanto
 * il proprio snapshot: modificare un template non deve alterare le release gia
 * avviate ne lo storico dei rilasci.
 *
 * Un template non si cancella (vedi `WorkflowTemplatePolicy::delete`): si
 * disattiva, perche vi si appoggiano progetti e release.
 *
 * @property bool $is_active
 * @property bool $is_default
 */
#[Fillable(['name', 'description', 'is_active', 'is_default'])]
class WorkflowTemplate extends Model
{
    /** @use HasFactory<WorkflowTemplateFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Step del processo, sempre in ordine di esecuzione.
     *
     * L'ordine e parte del significato della relazione e non una scelta di chi
     * la interroga: un template e una **sequenza**, non un insieme di step.
     *
     * @return HasMany<StepDefinition, $this>
     */
    public function stepDefinitions(): HasMany
    {
        return $this->hasMany(StepDefinition::class)->orderBy('position');
    }

    /**
     * Progetti che hanno adottato questo processo.
     *
     * Serve a dire in elenco quanti progetti verrebbero toccati da una
     * disattivazione: e l'informazione che rende consapevole quella scelta.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Indica se il template puo essere usato per avviare una release: attivo e
     * con almeno uno step.
     *
     * Qui alimenta le segnalazioni nelle schermate di configurazione; **US-004**
     * lo invochera come precondizione dell'avvio di una release. Definirlo una
     * volta sola evita che la regola venga riscritta in due posti e diverga.
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->stepCount() > 0;
    }

    /**
     * Chiave di traduzione del motivo per cui il template non e utilizzabile,
     * `null` quando lo e.
     *
     * Il motivo e distinto e non generico: "disattivato" e "senza step" si
     * risolvono in due modi diversi, e un messaggio unico costringerebbe chi
     * configura a indovinare quale dei due sta bloccando l'avvio.
     */
    public function unusableReason(): ?string
    {
        if (! $this->is_active) {
            return 'templates.unusable_inactive';
        }

        if ($this->stepCount() === 0) {
            return 'templates.unusable_without_steps';
        }

        return null;
    }

    /**
     * Disattiva o riattiva il template.
     *
     * Disattivare il predefinito ne rimuove il flag: proporre ai nuovi progetti
     * un template inutilizzabile sarebbe un errore silenzioso.
     *
     * Il flag viene azzerato in **entrambe** le direzioni, e non e una
     * ridondanza: riattivare non deve poter restituire il ruolo di predefinito a
     * una riga che lo porti ancora per altra via — una correzione manuale, una
     * migrazione di dati — facendola convivere con il predefinito impostato nel
     * frattempo. Cosi questa e l'unica scrittura di `is_default` fuori da
     * `SetDefaultWorkflowTemplate` e puo soltanto azzerare: nessun secondo
     * percorso e in grado di creare un predefinito. Per tornare predefinito, un
     * template riattivato ripassa dalla Action.
     *
     * Una sola `update`: l'operazione e atomica senza transazione esplicita.
     */
    public function toggleActivation(): void
    {
        $this->update([
            'is_active' => ! $this->is_active,
            'is_default' => false,
        ]);
    }

    /**
     * Numero di step del template, riusando il conteggio gia caricato quando c'e.
     *
     * Stessa lezione di `Role::referenceCounts()`: un metodo chiamato per riga in
     * elenco non deve produrre una query per riga, altrimenti il `withCount()`
     * messo per evitare l'N+1 non serve a nulla.
     */
    private function stepCount(): int
    {
        if (array_key_exists('step_definitions_count', $this->attributes)) {
            return (int) $this->attributes['step_definitions_count'];
        }

        if ($this->relationLoaded('stepDefinitions')) {
            return $this->stepDefinitions->count();
        }

        return $this->stepDefinitions()->count();
    }

    /**
     * Template attivi, gli unici proponibili su un progetto.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
