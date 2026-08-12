<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Progetto su cui il team rilascia: contenitore delle proprie release e del
 * proprio storico.
 *
 * I progetti non si cancellano, si disattivano: cancellarne uno porterebbe via
 * lo storico dei rilasci, che e il valore che lo strumento accumula nel tempo.
 *
 * L'associazione al template di workflow arriva con US-003, quando il template
 * esiste.
 *
 * @property bool $is_active
 */
#[Fillable(['name', 'slug', 'description', 'is_active'])]
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
