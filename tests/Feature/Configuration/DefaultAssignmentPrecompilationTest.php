<?php

namespace Tests\Feature\Configuration;

use App\Actions\Projects\CreateProject;
use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Il comportamento che questa spec chiede di dimostrare: la mappatura predefinita
 * si ritrova precompilata su un progetto nuovo, e da quel momento le due mappature
 * vivono separate.
 */
class DefaultAssignmentPrecompilationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_project_inherits_the_default_mapping(): void
    {
        $defaults = DefaultRoleAssignment::factory()->count(3)->create();

        $result = $this->createProject();

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(3, $result['project']->assignments);

        foreach ($defaults as $default) {
            $this->assertDatabaseHas('project_role_assignments', [
                'project_id' => $result['project']->id,
                'role_id' => $default->role_id,
                'user_id' => $default->user_id,
            ]);
        }
    }

    public function test_editing_the_project_mapping_leaves_the_default_untouched(): void
    {
        $default = DefaultRoleAssignment::factory()->create();
        $project = $this->createProject()['project'];
        $replacement = User::factory()->member()->create();

        $project->assignments()->where('role_id', $default->role_id)
            ->update(['user_id' => $replacement->id]);

        $this->assertDatabaseHas('default_role_assignments', [
            'id' => $default->id,
            'user_id' => $default->user_id,
        ]);
    }

    public function test_editing_the_default_mapping_leaves_existing_projects_untouched(): void
    {
        $default = DefaultRoleAssignment::factory()->create();
        $inheritedUserId = $default->user_id;
        $project = $this->createProject()['project'];
        $replacement = User::factory()->member()->create();

        $default->update(['user_id' => $replacement->id]);

        $this->assertDatabaseHas('project_role_assignments', [
            'project_id' => $project->id,
            'role_id' => $default->role_id,
            'user_id' => $inheritedUserId,
        ]);
    }

    public function test_a_default_pointing_at_a_deactivated_person_is_not_copied(): void
    {
        DefaultRoleAssignment::factory()->create();
        DefaultRoleAssignment::factory()
            ->for(User::factory()->member()->inactive())
            ->create();

        $result = $this->createProject();

        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['project']->assignments);
    }

    public function test_a_default_on_a_deactivated_role_is_not_copied(): void
    {
        DefaultRoleAssignment::factory()->create();
        DefaultRoleAssignment::factory()->for(Role::factory()->inactive())->create();

        $result = $this->createProject();

        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['project']->assignments);
    }

    public function test_a_project_without_default_mapping_is_created_with_no_assignments(): void
    {
        $result = $this->createProject();

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(0, $result['project']->assignments);
    }

    public function test_nothing_is_persisted_when_the_copy_fails(): void
    {
        $default = DefaultRoleAssignment::factory()->create();

        // Il ruolo sparisce subito dopo la lettura delle predefinite e prima della
        // scrittura delle assegnazioni: l'insert viola la chiave esterna e la
        // transazione deve riportare indietro anche il progetto appena creato.
        $sabotaged = false;

        DB::listen(function ($query) use ($default, &$sabotaged): void {
            if ($sabotaged || ! str_contains($query->sql, 'from "default_role_assignments"')) {
                return;
            }

            $sabotaged = true;

            DB::table('default_role_assignments')->where('id', $default->id)->delete();
            DB::table('roles')->where('id', $default->role_id)->delete();
        });

        try {
            $this->createProject();
            $this->fail('La creazione doveva fallire per violazione della chiave esterna.');
        } catch (QueryException) {
            // Atteso: si prosegue a verificare che nulla sia rimasto scritto.
        }

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_role_assignments', 0);
    }

    /**
     * @return array{project: Project, skipped: int}
     */
    private function createProject(): array
    {
        $result = app(CreateProject::class)->handle([
            'name' => 'Portale Fornitori',
            'slug' => 'portale-fornitori',
            'description' => 'Progetto di prova.',
        ]);

        $result['project']->load('assignments');

        return $result;
    }
}
