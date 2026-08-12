<?php

namespace App\Models;

use App\Models\Concerns\OrderedByPosition;
use Database\Factories\StepDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passaggio ordinato di un template di workflow: cosa va fatto, chi ne risponde e
 * quali informazioni servono per considerarlo concluso.
 *
 * Lo step nomina un **ruolo** e non una persona: e cio che rende lo stesso
 * template utilizzabile su progetti con team diversi. La persona si ottiene
 * all'avvio della release, risolvendo il ruolo sulla mappatura del progetto.
 *
 * E definizione: l'esecuzione di una release legge il proprio snapshot, mai
 * queste righe.
 *
 * @property int $position
 * @property string $workflow_template_id
 */
#[Fillable(['workflow_template_id', 'position', 'name', 'instructions', 'role_id'])]
class StepDefinition extends Model
{
    /** @use HasFactory<StepDefinitionFactory> */
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
        ];
    }

    /**
     * Template a cui lo step appartiene.
     *
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    /**
     * Ruolo responsabile dello step.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * La sequenza di uno step sono gli step dello stesso template.
     *
     * @return Builder<static>
     */
    public function sequence(): Builder
    {
        return static::query()->where('workflow_template_id', $this->workflow_template_id);
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
