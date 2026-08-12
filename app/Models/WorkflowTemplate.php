<?php

namespace App\Models;

use Database\Factories\WorkflowTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     * Template attivi, gli unici proponibili su un progetto.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
