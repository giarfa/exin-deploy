<?php

namespace Tests\Unit\Models;

use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_receives_a_uuid_primary_key(): void
    {
        $template = WorkflowTemplate::factory()->create();

        $this->assertTrue(Str::isUuid($template->id), "L'id [{$template->id}] non e un UUID valido.");
    }

    public function test_it_is_active_and_not_default_out_of_the_box(): void
    {
        $template = WorkflowTemplate::factory()->create();

        // `assertSame` e non `assertTrue`: verifica anche che il cast abbia
        // prodotto un booleano e non l'intero letto dalla colonna.
        $this->assertSame(true, $template->is_active);
        $this->assertSame(false, $template->is_default);
    }

    public function test_the_factory_states_produce_a_deactivated_and_a_default_template(): void
    {
        $this->assertFalse(WorkflowTemplate::factory()->inactive()->create()->is_active);
        $this->assertTrue(WorkflowTemplate::factory()->isDefault()->create()->is_default);
    }

    public function test_the_active_scope_excludes_deactivated_templates(): void
    {
        $active = WorkflowTemplate::factory()->create();
        $inactive = WorkflowTemplate::factory()->inactive()->create();

        $found = WorkflowTemplate::active()->pluck('id');

        $this->assertTrue($found->contains($active->id));
        $this->assertFalse($found->contains($inactive->id));
    }

    public function test_the_steps_relation_is_ordered_by_position(): void
    {
        // Create in ordine sparso: l'ordine e parte del significato della
        // relazione, non una scelta di chi la interroga.
        $template = WorkflowTemplate::factory()->create();

        StepDefinition::factory()->for($template)->create(['position' => 3, 'name' => 'Terzo']);
        StepDefinition::factory()->for($template)->create(['position' => 1, 'name' => 'Primo']);
        StepDefinition::factory()->for($template)->create(['position' => 2, 'name' => 'Secondo']);

        $this->assertSame(
            ['Primo', 'Secondo', 'Terzo'],
            $template->stepDefinitions()->pluck('name')->all()
        );
    }

    public function test_the_with_steps_state_builds_a_contiguous_sequence(): void
    {
        $template = WorkflowTemplate::factory()->withSteps(4)->create();

        $this->assertSame([1, 2, 3, 4], $template->stepDefinitions()->pluck('position')->all());
    }
}
