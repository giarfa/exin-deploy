<?php

namespace App\Models;

use App\Enums\FieldType;
use App\Models\Concerns\OrderedByPosition;
use Database\Factories\FieldDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Informazione che il responsabile di uno step deve fornire per considerarlo
 * concluso: etichetta, forma, obbligatorieta e testo di aiuto.
 *
 * Un campo obbligatorio non compilato impedira la chiusura dello step; uno
 * facoltativo no. E definizione: all'avvio di una release queste righe vengono
 * copiate nello snapshot che l'esecuzione compilera (US-004).
 *
 * @property int $position
 * @property bool $is_required
 * @property FieldType $type
 * @property string $step_definition_id
 */
#[Fillable(['step_definition_id', 'position', 'label', 'type', 'is_required', 'help_text'])]
class FieldDefinition extends Model
{
    /** @use HasFactory<FieldDefinitionFactory> */
    use HasFactory, HasUuids, OrderedByPosition;

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
     * @return BelongsTo<StepDefinition, $this>
     */
    public function stepDefinition(): BelongsTo
    {
        return $this->belongsTo(StepDefinition::class);
    }

    /**
     * La sequenza di un campo sono i campi dello stesso step.
     *
     * @return Builder<static>
     */
    public function sequence(): Builder
    {
        return static::query()->where('step_definition_id', $this->step_definition_id);
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
