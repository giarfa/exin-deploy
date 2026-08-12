<?php

namespace App\Models;

use App\Enums\ReleaseStepStatus;
use Carbon\CarbonInterface;
use Database\Factories\ReleaseStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Passaggio di una release avviata: la copia congelata di uno step del template,
 * con in piu cio che la definizione non sa — chi ne risponde e a che punto e.
 *
 * Nome, istruzioni, posizione e nome del ruolo sono **copiati** all'avvio: da quel
 * momento modificare, riordinare o cancellare la definizione non tocca questa
 * riga. E il motivo per cui esiste una tabella separata invece di un riferimento.
 *
 * `role_name` e la fonte di verita per la lettura; `role_id` resta accanto solo
 * per rendere il ruolo non cancellabile. Rinominare un ruolo non deve riscrivere
 * lo storico dei rilasci gia eseguiti.
 *
 * **Nessun uso di `OrderedByPosition`**, ed e deliberato: quel trait serve a
 * rinumerare una sequenza modificabile, e lo snapshot non si riordina. Averlo
 * qui aprirebbe un percorso di scrittura verso l'ordine congelato, cioe verso
 * l'unica cosa che questa tabella esiste per proteggere.
 *
 * @property int $position
 * @property ReleaseStepStatus $status
 * @property CarbonInterface|null $completed_at
 * @property string $release_id
 */
#[Fillable([
    'release_id',
    'position',
    'name',
    'instructions',
    'role_id',
    'role_name',
    'assigned_user_id',
    'status',
])]
class ReleaseStep extends Model
{
    /** @use HasFactory<ReleaseStepFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => ReleaseStepStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Release a cui lo step appartiene.
     *
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * Ruolo che era responsabile dello step al momento dell'avvio.
     *
     * Serve a rendere il ruolo non cancellabile e a risalire al catalogo; per
     * mostrare il ruolo si legge `role_name`, che e congelato.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Persona responsabile dello step, risolta all'avvio dalla mappatura del
     * progetto.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Persona che ha chiuso lo step; `null` finche non e completato.
     *
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Campi da fornire per chiudere lo step, sempre in ordine di compilazione.
     *
     * L'ordine e parte del significato della relazione e non una scelta di chi la
     * interroga, come per `StepDefinition::fieldDefinitions()`.
     *
     * @return HasMany<ReleaseStepField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(ReleaseStepField::class)->orderBy('position');
    }

    /**
     * Step in ordine di esecuzione.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position');
    }
}
