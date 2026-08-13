<?php

namespace App\Models;

use App\Enums\ReleaseStatus;
use Carbon\CarbonInterface;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rilascio avviato su un progetto: il contenitore dello snapshot congelato del
 * processo e del suo avanzamento.
 *
 * E **istanza**, non definizione. All'avvio (`App\Actions\Releases\StartRelease`)
 * step e campi del template vengono copiati in `release_steps` e
 * `release_step_fields`; da quel momento l'esecuzione legge soltanto quelle
 * righe. Modificare, riordinare o disattivare il template non altera una release
 * gia avviata.
 *
 * Il legame con il template dice **da dove** la release e nata, e non e una
 * dipendenza di lettura: nessun percorso di esecuzione risale a
 * `workflowTemplate` per sapere come procedere.
 *
 * Le colonne di conclusione (`completed_by`, `completed_at`) restano vuote finche la
 * release e in corso: le scrive `App\Actions\Releases\CloseStep` chiudendo l'ultimo
 * step della catena, nella stessa transazione. Una release conclusa e in sola
 * lettura — nessuno step si riapre, nemmeno per un amministratore (la riapertura e
 * FR-019, fuori perimetro).
 *
 * @property ReleaseStatus $status
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $completed_at
 * @property string $project_id
 * @property string $workflow_template_id
 */
#[Fillable(['project_id', 'workflow_template_id', 'label', 'status', 'started_by', 'started_at'])]
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReleaseStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Progetto su cui il rilascio avviene.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Template da cui lo snapshot e stato copiato.
     *
     * Solo provenienza: leggerlo per decidere come procedere significherebbe
     * riportare la definizione dentro l'esecuzione, che e esattamente cio che lo
     * snapshot esiste per impedire.
     *
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    /**
     * Catena congelata degli step, sempre in ordine di esecuzione.
     *
     * E l'**unica** fonte di verita dell'esecuzione: nessun percorso deve risalire
     * a `workflowTemplate->stepDefinitions` per sapere come procede questa release.
     *
     * @return HasMany<ReleaseStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ReleaseStep::class)->orderBy('position');
    }

    /**
     * Persona che ha avviato il rilascio.
     *
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Persona che ha concluso il rilascio; `null` finche la release e in corso.
     *
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Release in corso, le uniche su cui si avanza.
     */
    #[Scope]
    protected function inProgress(Builder $query): void
    {
        $query->where('status', ReleaseStatus::InProgress);
    }
}
