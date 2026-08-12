<?php

namespace Tests\Unit\Models;

use App\Models\FieldDefinition;
use App\Models\StepDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StepDefinitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_receives_a_uuid_primary_key(): void
    {
        $step = StepDefinition::factory()->create();

        $this->assertTrue(Str::isUuid($step->id), "L'id [{$step->id}] non e un UUID valido.");
    }

    public function test_it_names_a_role_and_belongs_to_a_template(): void
    {
        $step = StepDefinition::factory()->create();

        $this->assertNotNull($step->role);
        $this->assertNotNull($step->workflowTemplate);
    }

    public function test_the_position_is_read_back_as_an_integer(): void
    {
        $this->assertSame(1, StepDefinition::factory()->create()->fresh()->position);
    }

    public function test_the_factory_places_new_steps_at_the_end_of_the_template(): void
    {
        $step = StepDefinition::factory()->create();

        $next = StepDefinition::factory()
            ->for($step->workflowTemplate)
            ->create();

        $this->assertSame($step->position + 1, $next->position);
    }

    public function test_instructions_are_optional(): void
    {
        $this->assertNull(StepDefinition::factory()->withoutInstructions()->create()->instructions);
    }

    public function test_the_fields_relation_is_ordered_by_position(): void
    {
        $step = StepDefinition::factory()->create();

        FieldDefinition::factory()->for($step)->create(['position' => 3, 'label' => 'Terzo']);
        FieldDefinition::factory()->for($step)->create(['position' => 1, 'label' => 'Primo']);
        FieldDefinition::factory()->for($step)->create(['position' => 2, 'label' => 'Secondo']);

        $this->assertSame(
            ['Primo', 'Secondo', 'Terzo'],
            $step->fieldDefinitions()->pluck('label')->all()
        );
    }

    public function test_the_ordered_scope_sorts_by_position(): void
    {
        $template = StepDefinition::factory()->create()->workflowTemplate;

        StepDefinition::factory()->count(3)->for($template)->create();

        $this->assertSame(
            [1, 2, 3, 4],
            StepDefinition::query()
                ->where('workflow_template_id', $template->id)
                ->ordered()
                ->pluck('position')
                ->all()
        );
    }
}
