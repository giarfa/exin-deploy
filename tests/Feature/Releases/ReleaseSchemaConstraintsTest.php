<?php

namespace Tests\Feature\Releases;

use App\Enums\FieldType;
use App\Enums\ReleaseStepStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * I vincoli dello snapshot verificati scrivendo direttamente con `DB::table()`,
 * aggirando modelli e validazione: un test che passa dalla validazione non
 * dimostra nulla sullo schema. Stessa convenzione di `WorkflowSchemaConstraintsTest`.
 */
class ReleaseSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_releases_cannot_share_a_label_on_the_same_project(): void
    {
        $release = Release::factory()->create(['label' => 'v2.4.0']);

        $this->expectException(QueryException::class);

        DB::table('releases')->insert([
            'id' => (string) Str::uuid7(),
            'project_id' => $release->project_id,
            'workflow_template_id' => $release->workflow_template_id,
            'label' => 'v2.4.0',
            'status' => $release->status->value,
            'started_by' => $release->started_by,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_same_label_is_allowed_on_a_different_project(): void
    {
        $first = Release::factory()->create(['label' => 'v2.4.0']);
        $second = Release::factory()->create(['label' => 'v2.4.0']);

        $this->assertNotSame($first->project_id, $second->project_id);
        $this->assertSame('v2.4.0', $second->fresh()->label);
    }

    public function test_two_steps_cannot_share_a_position_in_the_same_release(): void
    {
        $step = ReleaseStep::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('release_steps')->insert([
            'id' => (string) Str::uuid7(),
            'release_id' => $step->release_id,
            'position' => $step->position,
            'name' => 'Passaggio in conflitto',
            'role_id' => $step->role_id,
            'role_name' => $step->role_name,
            'assigned_user_id' => $step->assigned_user_id,
            'status' => ReleaseStepStatus::Blocked->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_same_position_is_allowed_on_different_releases(): void
    {
        $first = ReleaseStep::factory()->create();
        $second = ReleaseStep::factory()->create();

        $this->assertSame($first->position, $second->position);
        $this->assertNotSame($first->release_id, $second->release_id);
    }

    public function test_two_fields_cannot_share_a_position_in_the_same_step(): void
    {
        $field = ReleaseStepField::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('release_step_fields')->insert([
            'id' => (string) Str::uuid7(),
            'release_step_id' => $field->release_step_id,
            'position' => $field->position,
            'label' => 'Campo in conflitto',
            'type' => FieldType::ShortText->value,
            'is_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_role_frozen_on_a_release_step_cannot_be_deleted(): void
    {
        $step = ReleaseStep::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $step->role_id)->delete();
    }

    public function test_a_member_responsible_for_a_release_step_cannot_be_deleted(): void
    {
        $step = ReleaseStep::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $step->assigned_user_id)->delete();
    }

    public function test_a_project_with_releases_cannot_be_deleted(): void
    {
        $release = Release::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('projects')->where('id', $release->project_id)->delete();
    }

    public function test_a_template_a_release_was_started_from_cannot_be_deleted(): void
    {
        $release = Release::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('workflow_templates')->where('id', $release->workflow_template_id)->delete();
    }

    public function test_deleting_a_release_removes_its_steps_fields_and_events(): void
    {
        $field = ReleaseStepField::factory()->create();
        $step = $field->releaseStep;
        $event = ReleaseEvent::factory()->for($step->release)->create();

        DB::table('releases')->where('id', $step->release_id)->delete();

        $this->assertDatabaseMissing('release_steps', ['id' => $step->id]);
        $this->assertDatabaseMissing('release_step_fields', ['id' => $field->id]);
        $this->assertDatabaseMissing('release_events', ['id' => $event->id]);
    }

    public function test_deleting_a_step_removes_its_fields(): void
    {
        $field = ReleaseStepField::factory()->create();

        DB::table('release_steps')->where('id', $field->release_step_id)->delete();

        $this->assertDatabaseMissing('release_step_fields', ['id' => $field->id]);
    }

    public function test_the_factory_keeps_the_release_template_aligned_with_its_project(): void
    {
        // Una release che nomina un template diverso da quello del proprio progetto
        // descriverebbe uno stato che l'avvio non puo produrre.
        $project = Project::factory()->withTemplate()->create();

        $release = Release::factory()->for($project)->create();

        $this->assertSame($project->workflow_template_id, $release->workflow_template_id);
    }
}
