<?php

namespace App\Models;

use App\Enums\FieldType;
use Database\Factories\ReleaseStepFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Informazione richiesta da uno step di una release avviata, e il valore fornito
 * chiudendolo.
 *
 * Etichetta, forma, obbligatorieta e testo di aiuto sono la copia congelata della
 * definizione: cambiarla dopo l'avvio non cambia cosa questa release chiede.
 *
 * `value` e una sola colonna testuale per tutti e quattro i tipi. La semantica
 * per tipo — un link deve essere un indirizzo valido, una conferma obbligatoria
 * deve risultare spuntata — appartiene alla chiusura dello step (US-005), che qui
 * scrive soltanto il valore gia validato.
 *
 * **Nessun uso di `OrderedByPosition`**: come per `ReleaseStep`, lo snapshot non
 * si riordina e un percorso di rinumerazione sarebbe una porta aperta sull'ordine
 * congelato.
 *
 * @property int $position
 * @property bool $is_required
 * @property FieldType $type
 * @property string $release_step_id
 */
#[Fillable([
    'release_step_id',
    'position',
    'label',
    'type',
    'is_required',
    'help_text',
    'value',
])]
class ReleaseStepField extends Model
{
    /** @use HasFactory<ReleaseStepFieldFactory> */
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
            'type' => FieldType::class,
            'is_required' => 'boolean',
        ];
    }

    /**
     * Step che richiede questo campo.
     *
     * @return BelongsTo<ReleaseStep, $this>
     */
    public function releaseStep(): BelongsTo
    {
        return $this->belongsTo(ReleaseStep::class);
    }

    /**
     * Campi nell'ordine in cui vanno compilati.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position');
    }
}
