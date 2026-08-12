<?php

namespace Tests\Feature\Configuration;

use App\Enums\FieldType;
use App\Models\FieldDefinition;
use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * I vincoli che il criterio di accettazione chiede "a livello di schema" sono
 * verificati scrivendo direttamente con `DB::table()`, aggirando modelli e
 * validazione: un test che passa dalla validazione non dimostra nulla sullo
 * schema. Stessa convenzione di `SchemaConstraintsTest`.
 */
class WorkflowSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_key_constraints_are_enforced_on_the_connection(): void
    {
        // Prerequisito delle verifiche su `restrict` piu sotto: senza il pragma
        // passerebbero per il motivo sbagliato, cioe nessun vincolo.
        $this->assertSame(1, DB::select('PRAGMA foreign_keys')[0]->foreign_keys);
    }

    public function test_a_duplicate_template_name_is_rejected_by_the_database(): void
    {
        WorkflowTemplate::factory()->create(['name' => 'Rilascio standard']);

        $this->expectException(QueryException::class);

        DB::table('workflow_templates')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Rilascio standard',
            'is_active' => true,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_two_steps_cannot_share_a_position_in_the_same_template(): void
    {
        $step = StepDefinition::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('step_definitions')->insert([
            'id' => (string) Str::uuid7(),
            'workflow_template_id' => $step->workflow_template_id,
            'position' => $step->position,
            'name' => 'Passaggio in conflitto',
            'role_id' => $step->role_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_same_position_is_allowed_on_different_templates(): void
    {
        $first = StepDefinition::factory()->create();
        $second = StepDefinition::factory()->create();

        $this->assertSame($first->position, $second->position);
        $this->assertNotSame($first->workflow_template_id, $second->workflow_template_id);
    }

    public function test_two_fields_cannot_share_a_position_in_the_same_step(): void
    {
        $field = FieldDefinition::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('field_definitions')->insert([
            'id' => (string) Str::uuid7(),
            'step_definition_id' => $field->step_definition_id,
            'position' => $field->position,
            'label' => 'Campo in conflitto',
            'type' => FieldType::ShortText->value,
            'is_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_same_position_is_allowed_on_different_steps(): void
    {
        $first = FieldDefinition::factory()->create();
        $second = FieldDefinition::factory()->create();

        $this->assertSame($first->position, $second->position);
        $this->assertNotSame($first->step_definition_id, $second->step_definition_id);
    }

    public function test_a_role_responsible_for_a_step_cannot_be_deleted(): void
    {
        $step = StepDefinition::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $step->role_id)->delete();
    }

    public function test_deleting_a_template_removes_its_steps_and_their_fields(): void
    {
        $field = FieldDefinition::factory()->create();
        $step = $field->stepDefinition;

        DB::table('workflow_templates')->where('id', $step->workflow_template_id)->delete();

        $this->assertDatabaseMissing('step_definitions', ['id' => $step->id]);
        $this->assertDatabaseMissing('field_definitions', ['id' => $field->id]);
    }

    public function test_deleting_a_step_removes_its_fields(): void
    {
        $field = FieldDefinition::factory()->create();

        DB::table('step_definitions')->where('id', $field->step_definition_id)->delete();

        $this->assertDatabaseMissing('field_definitions', ['id' => $field->id]);
    }
}
