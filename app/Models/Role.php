<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ruolo funzionale del processo di rilascio (Dev Lead, QA, DevOps, ...).
 *
 * Non e il livello applicativo di un membro (`App\Enums\UserLevel`): quello decide
 * chi puo configurare il sistema, questo esprime la responsabilita di uno step.
 * I template di workflow parlano di ruoli e non di persone, cosi che lo stesso
 * template funzioni su progetti con persone diverse.
 *
 * @property bool $is_active
 */
#[Fillable(['name', 'description', 'is_active'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasUuids;

    /**
     * Relazioni che rendono un ruolo non cancellabile.
     *
     * Punto di estensione unico della regola: US-003 aggiungera `stepDefinitions`
     * (step di template) e US-004 `releaseSteps` (step di release gia avviate).
     * Chi introduce una nuova tabella che referenzia i ruoli aggiunge il nome
     * della relazione qui, e la regola vale ovunque senza altre modifiche.
     *
     * @var list<string>
     */
    private const REFERENCING_RELATIONS = ['projectAssignments', 'defaultAssignment'];

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
     * Assegnazioni del ruolo sui singoli progetti.
     *
     * @return HasMany<ProjectRoleAssignment, $this>
     */
    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ProjectRoleAssignment::class);
    }

    /**
     * Persona che ricopre il ruolo per impostazione predefinita nel team.
     *
     * @return HasOne<DefaultRoleAssignment, $this>
     */
    public function defaultAssignment(): HasOne
    {
        return $this->hasOne(DefaultRoleAssignment::class);
    }

    /**
     * Indica se il ruolo e referenziato, e quindi non cancellabile.
     *
     * Un ruolo referenziato resta disattivabile: la disattivazione lo toglie dalle
     * scelte future senza riscrivere il passato.
     */
    public function isReferenced(): bool
    {
        return $this->referenceCounts()->sum() > 0;
    }

    /**
     * Numero di riferimenti per relazione, usato per spiegare all'utente perche
     * la cancellazione e stata rifiutata.
     *
     * @return Collection<string, int>
     */
    public function referenceCounts()
    {
        $this->loadCount(self::REFERENCING_RELATIONS);

        return collect(self::REFERENCING_RELATIONS)
            ->mapWithKeys(fn (string $relation): array => [
                $relation => (int) $this->getAttribute(Str::snake($relation).'_count'),
            ]);
    }

    /**
     * Ruoli attivi, gli unici proponibili in una nuova assegnazione.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
