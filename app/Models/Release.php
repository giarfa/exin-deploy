<?php

namespace App\Models;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
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
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * Step su cui la release e ferma; `null` quando e conclusa.
     *
     * E una **relazione** e non un metodo che filtra la catena gia caricata,
     * perche deve essere caricabile in eager loading (`with('activeStep')`): un
     * elenco di release che risalisse allo step attivo riga per riga pagherebbe
     * una query per release, che e esattamente cio che la vista operativa non puo
     * permettersi.
     *
     * Regge sull'invariante mantenuta da `App\Actions\Releases\CloseStep`: al
     * massimo uno step attivo per release, zero quando la release e conclusa.
     * Su una release conclusa `null` e quindi un esito legittimo e non un difetto
     * dei dati — chi la rende deve prevederlo.
     *
     * Nasce per "i miei step" (US-007) ma e un seam condiviso: l'elenco delle
     * release (US-009) leggera lo stesso stato. Il dettaglio della release **non**
     * la usa, e non per svista: quella schermata carica l'intera catena, e lo step
     * attivo lo trova fra gli step gia in memoria invece di pagare una seconda
     * query per un dato che ha gia.
     *
     * `chaperone()` non e una rifinitura: `ReleaseStep::activationInstant()` ripiega
     * su `release->started_at` quando lo step attivo e il primo della catena — cioe
     * su ogni release appena avviata — e senza la relazione inversa gia popolata
     * quel ripiego caricherebbe la release **una query per riga**, riportando l'N+1
     * proprio nel blocco che esiste per dire da quanto qualcuno e fermo.
     *
     * @return HasOne<ReleaseStep, $this>
     */
    public function activeStep(): HasOne
    {
        return $this->hasOne(ReleaseStep::class)
            ->where('status', ReleaseStepStatus::Active)
            ->chaperone();
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

    /**
     * Release che coinvolgono una persona: quelle in cui le e stato assegnato
     * almeno uno step, **in qualunque stato**.
     *
     * Lo stato non entra nel filtro deliberatamente: chi ha gia chiuso il proprio
     * step resta coinvolto nel rilascio, e sapere su chi si e fermato dopo di lui
     * e proprio l'informazione che il blocco delle release in attesa esiste per
     * dare. Restringere agli step attivi lo svuoterebbe.
     */
    #[Scope]
    protected function involving(Builder $query, User $user): void
    {
        $query->whereHas('steps', fn (Builder $steps) => $steps->where('assigned_user_id', $user->id));
    }
}
