<?php

namespace Tests\Feature\Configuration;

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * I criteri di accettazione chiedono che alcuni vincoli siano applicati "a livello
 * di schema e non solo di validazione". Questi test scrivono direttamente con
 * `DB::table()`, aggirando modelli e Form Request: e l'unico modo di dimostrare che
 * il vincolo esiste davvero nel database.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_key_constraints_are_enforced_on_the_connection(): void
    {
        // Senza questo pragma le verifiche sulle chiavi esterne piu sotto
        // passerebbero per il motivo sbagliato: nessun vincolo, nessun errore.
        $this->assertSame(1, DB::select('PRAGMA foreign_keys')[0]->foreign_keys);
    }

    public function test_a_duplicate_project_slug_is_rejected_by_the_database(): void
    {
        Project::factory()->create(['slug' => 'portale-clienti']);

        $this->expectException(QueryException::class);

        DB::table('projects')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Altro progetto',
            'slug' => 'portale-clienti',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_duplicate_role_name_is_rejected_by_the_database(): void
    {
        Role::factory()->create(['name' => 'QA']);

        $this->expectException(QueryException::class);

        DB::table('roles')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'QA',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_one_person_per_project_and_role_pair(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();
        $other = User::factory()->member()->create();

        $this->expectException(QueryException::class);

        DB::table('project_role_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'project_id' => $assignment->project_id,
            'role_id' => $assignment->role_id,
            'user_id' => $other->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_one_default_person_per_role(): void
    {
        $assignment = DefaultRoleAssignment::factory()->create();
        $other = User::factory()->member()->create();

        $this->expectException(QueryException::class);

        DB::table('default_role_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'role_id' => $assignment->role_id,
            'user_id' => $other->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_role_referenced_by_a_project_mapping_cannot_be_deleted(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $assignment->role_id)->delete();
    }

    public function test_a_role_referenced_by_the_default_mapping_cannot_be_deleted(): void
    {
        $assignment = DefaultRoleAssignment::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $assignment->role_id)->delete();
    }

    public function test_an_assigned_member_cannot_be_deleted(): void
    {
        // I membri non si cancellano, si disattivano: il vincolo protegge la
        // traccia storica anche da una cancellazione fuori dall'applicazione.
        $assignment = ProjectRoleAssignment::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $assignment->user_id)->delete();
    }

    public function test_deleting_a_project_removes_its_assignments(): void
    {
        $assignment = ProjectRoleAssignment::factory()->create();

        DB::table('projects')->where('id', $assignment->project_id)->delete();

        $this->assertDatabaseMissing('project_role_assignments', ['id' => $assignment->id]);
    }
}
