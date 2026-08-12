<?php

namespace Tests\Unit\Models;

use App\Models\FieldDefinition;
use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Criterio di accettazione verificato da test: "dopo un riordino o una
 * cancellazione le posizioni restano contigue e senza duplicati".
 *
 * Ha un test dedicato e non un'asserzione dentro un test piu grande, perche e la
 * regola che rompendosi rende incomprensibile il processo configurato: posizioni
 * duplicate o con salti trasformano una sequenza in un insieme disordinato.
 */
class OrderedByPositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_step_sequence_stays_contiguous_through_moves_and_deletions(): void
    {
        $template = WorkflowTemplate::factory()->create();
        StepDefinition::factory()->count(5)->for($template)->create();

        $steps = fn (): Collection => StepDefinition::query()
            ->where('workflow_template_id', $template->id)
            ->ordered()
            ->get();

        $this->assertContiguous($steps());
        $names = $steps()->pluck('name')->all();

        // Il terzo sale: scambio con il secondo.
        $steps()->firstWhere('position', 3)->moveUp();
        $this->assertContiguous($steps());
        $this->assertSame(
            [$names[0], $names[2], $names[1], $names[3], $names[4]],
            $steps()->pluck('name')->all()
        );

        // Il secondo scende: torna dov'era.
        $steps()->firstWhere('position', 2)->moveDown();
        $this->assertContiguous($steps());
        $this->assertSame($names, $steps()->pluck('name')->all());

        // Estremi: il primo verso l'alto e l'ultimo verso il basso non fanno nulla.
        $steps()->first()->moveUp();
        $this->assertContiguous($steps());
        $this->assertSame($names, $steps()->pluck('name')->all());

        $steps()->last()->moveDown();
        $this->assertContiguous($steps());
        $this->assertSame($names, $steps()->pluck('name')->all());

        // Cancellazione di un elemento centrale: la sequenza si richiude.
        $steps()->firstWhere('position', 3)->deleteAndResequence();
        $this->assertContiguous($steps());
        $this->assertSame(
            [$names[0], $names[1], $names[3], $names[4]],
            $steps()->pluck('name')->all()
        );

        // Cancellazione del primo: tutti scalano di uno.
        $steps()->first()->deleteAndResequence();
        $this->assertContiguous($steps());
        $this->assertSame([$names[1], $names[3], $names[4]], $steps()->pluck('name')->all());

        // Aggiunta in coda: la posizione libera e esattamente la successiva.
        $added = StepDefinition::factory()->for($template)->create([
            'position' => $steps()->first()->nextPosition(),
            'name' => 'Passaggio aggiunto',
        ]);

        $this->assertSame(4, $added->position);
        $this->assertContiguous($steps());
    }

    public function test_the_field_sequence_follows_the_same_rules(): void
    {
        // Lo stesso percorso su un altro modello: il concern non e tarato sugli step.
        $step = StepDefinition::factory()->create();
        FieldDefinition::factory()->count(4)->for($step)->create();

        $fields = fn (): Collection => FieldDefinition::query()
            ->where('step_definition_id', $step->id)
            ->ordered()
            ->get();

        $this->assertContiguous($fields());
        $labels = $fields()->pluck('label')->all();

        $fields()->last()->moveUp();
        $this->assertContiguous($fields());
        $this->assertSame(
            [$labels[0], $labels[1], $labels[3], $labels[2]],
            $fields()->pluck('label')->all()
        );

        $fields()->first()->moveUp();
        $this->assertContiguous($fields());

        $fields()->firstWhere('position', 2)->deleteAndResequence();
        $this->assertContiguous($fields());
        $this->assertSame([$labels[0], $labels[3], $labels[2]], $fields()->pluck('label')->all());
    }

    public function test_reordering_one_template_does_not_touch_another(): void
    {
        $first = WorkflowTemplate::factory()->create();
        $second = WorkflowTemplate::factory()->create();

        StepDefinition::factory()->count(3)->for($first)->create();
        StepDefinition::factory()->count(3)->for($second)->create();

        $untouched = StepDefinition::query()
            ->where('workflow_template_id', $second->id)
            ->ordered()
            ->pluck('name', 'position')
            ->all();

        StepDefinition::query()
            ->where('workflow_template_id', $first->id)
            ->ordered()
            ->first()
            ->deleteAndResequence();

        $this->assertSame(
            $untouched,
            StepDefinition::query()
                ->where('workflow_template_id', $second->id)
                ->ordered()
                ->pluck('name', 'position')
                ->all()
        );
    }

    public function test_reordering_the_fields_of_one_step_does_not_touch_another(): void
    {
        $template = WorkflowTemplate::factory()->create();
        [$first, $second] = StepDefinition::factory()->count(2)->for($template)->create()->all();

        FieldDefinition::factory()->count(3)->for($first)->create();
        FieldDefinition::factory()->count(3)->for($second)->create();

        $untouched = FieldDefinition::query()
            ->where('step_definition_id', $second->id)
            ->ordered()
            ->pluck('label', 'position')
            ->all();

        FieldDefinition::query()
            ->where('step_definition_id', $first->id)
            ->ordered()
            ->first()
            ->moveDown();

        $this->assertSame(
            $untouched,
            FieldDefinition::query()
                ->where('step_definition_id', $second->id)
                ->ordered()
                ->pluck('label', 'position')
                ->all()
        );
    }

    public function test_deleting_a_step_takes_its_fields_without_disturbing_the_siblings(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $steps = StepDefinition::factory()->count(3)->for($template)->create();

        $steps->each(fn (StepDefinition $step) => FieldDefinition::factory()->count(2)->for($step)->create());

        $removed = $steps[1];
        $removed->deleteAndResequence();

        $this->assertDatabaseMissing('field_definitions', ['step_definition_id' => $removed->id]);

        $survivors = StepDefinition::query()
            ->where('workflow_template_id', $template->id)
            ->ordered()
            ->get();

        $this->assertContiguous($survivors);

        foreach ($survivors as $step) {
            $this->assertContiguous($step->fieldDefinitions);
        }
    }

    /**
     * Le posizioni della sequenza sono esattamente `1..N`: senza duplicati e
     * senza salti.
     *
     * @param  Collection<int, Model>  $sequence
     */
    private function assertContiguous(Collection $sequence): void
    {
        $positions = $sequence->pluck('position')->all();
        // `range(1, 0)` vale `[1, 0]`: su una sequenza vuota l'attesa e l'insieme vuoto.
        $expected = $sequence->isEmpty() ? [] : range(1, $sequence->count());

        $this->assertSame(
            $expected,
            $positions,
            'Posizioni attese 1..'.$sequence->count().', trovate: '.implode(',', $positions).'.'
        );
    }
}
