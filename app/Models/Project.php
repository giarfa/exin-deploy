<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Progetto su cui il team rilascia: contenitore delle proprie release e del
 * proprio storico.
 *
 * I progetti non si cancellano, si disattivano: cancellarne uno porterebbe via
 * lo storico dei rilasci, che e il valore che lo strumento accumula nel tempo.
 *
 * Il template di workflow associato descrive **come** si rilascia su questo
 * progetto; la mappatura ruolo -> persona dice **chi** ne risponde. Servono
 * entrambi: un template i cui step nominano ruoli senza responsabile produce
 * step che nessuno puo chiudere.
 *
 * @property bool $is_active
 * @property string|null $workflow_template_id
 */
#[Fillable(['name', 'slug', 'description', 'is_active', 'workflow_template_id'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
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
        ];
    }

    /**
     * Mappatura ruolo -> persona valida su questo progetto.
     *
     * @return HasMany<ProjectRoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectRoleAssignment::class);
    }

    /**
     * Template di workflow che descrive il processo di rilascio del progetto.
     *
     * Nullable: un progetto puo esistere senza template, semplicemente non ci si
     * avviano release.
     *
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    /**
     * Rilasci avviati sul progetto, dal piu recente.
     *
     * E lo storico che rende un progetto non cancellabile: portarselo via
     * significherebbe portarsi via il valore che lo strumento accumula nel tempo
     * (vedi `ProjectPolicy::delete`). Il vincolo e applicato anche dallo schema,
     * con `restrict` sulla chiave esterna di `releases`.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class)->latest('started_at');
    }

    /**
     * Ruoli previsti dagli step del template associato che su questo progetto non
     * hanno un responsabile.
     *
     * E la segnalazione che evita di scoprire il buco all'avvio di una release,
     * quando lo step resterebbe senza nessuno che possa chiuderlo.
     *
     * Percorso a rischio N+1 per costruzione, perche compare in elenco: quando le
     * relazioni sono gia precaricate questo metodo **non** interroga il database.
     * `loadMissing` e la rete di sicurezza per l'uso su un singolo progetto, non
     * una licenza a chiamarlo dentro un ciclo senza eager loading.
     *
     * @return Collection<int, Role>
     */
    public function uncoveredRoles(): Collection
    {
        $this->loadMissing(['workflowTemplate.stepDefinitions.role', 'assignments']);

        $template = $this->workflowTemplate;

        if ($template === null) {
            return collect();
        }

        $assignedRoleIds = $this->assignments->pluck('role_id')->all();

        return $template->stepDefinitions
            ->pluck('role')
            ->filter()
            ->reject(fn (Role $role): bool => in_array($role->id, $assignedRoleIds, true))
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Il binding di rotta resta sull'identificativo e non sullo slug: lo slug e
     * modificabile, quindi un collegamento gia diffuso si romperebbe alla prima
     * rinomina. Lo slug e un identificativo leggibile, non un indirizzo stabile.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Progetti attivi, gli unici su cui si avviano nuove release.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
