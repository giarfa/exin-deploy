<?php

namespace Tests\Feature\Configuration;

use App\Actions\Projects\CreateProject;
use App\Actions\Workflows\SetDefaultWorkflowTemplate;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chiusura dei due punti di FR-004 rinviati da US-002: il progetto adotta un
 * template, e il sistema dice quali ruoli previsti da quel template non hanno
 * ancora un responsabile.
 */
class ProjectTemplateAssociationTest extends TestCase
{
    use RefreshDatabase;

    private function create(array $attributes = []): array
    {
        return app(CreateProject::class)->handle([
            'name' => 'Portale Clienti',
            'slug' => 'portale-clienti',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_a_new_project_adopts_the_active_default_template(): void
    {
        $default = WorkflowTemplate::factory()->isDefault()->withSteps(2)->create();

        $result = $this->create();

        $this->assertSame($default->id, $result['project']->workflow_template_id);
        $this->assertSame($default->id, $result['template']?->id);
    }

    public function test_an_explicit_choice_wins_over_the_default(): void
    {
        WorkflowTemplate::factory()->isDefault()->withSteps(2)->create();
        $chosen = WorkflowTemplate::factory()->withSteps(3)->create();

        $result = $this->create(['workflow_template_id' => $chosen->id]);

        $this->assertSame($chosen->id, $result['project']->workflow_template_id);
    }

    public function test_without_any_default_the_project_is_born_without_a_template(): void
    {
        WorkflowTemplate::factory()->withSteps(2)->create();

        $result = $this->create();

        $this->assertNull($result['project']->workflow_template_id);
        $this->assertNull($result['template']);
    }

    public function test_a_deactivated_default_is_not_proposed(): void
    {
        // Il flag non sopravvive alla disattivazione, ma anche se ci arrivasse per
        // altra via un template inutilizzabile non va proposto a un progetto nuovo.
        $template = WorkflowTemplate::factory()->isDefault()->withSteps(2)->create();
        DB::table('workflow_templates')->where('id', $template->id)->update(['is_active' => false]);

        $this->assertNull($this->create()['project']->workflow_template_id);
    }

    public function test_the_template_stays_replaceable_after_creation(): void
    {
        $default = WorkflowTemplate::factory()->isDefault()->withSteps(2)->create();
        $other = WorkflowTemplate::factory()->withSteps(2)->create();

        $project = $this->create()['project'];
        $project->update(['workflow_template_id' => $other->id]);

        $this->assertSame($other->id, $project->fresh()->workflow_template_id);
        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_changing_the_default_does_not_touch_existing_projects(): void
    {
        // Stessa semantica della mappatura predefinita di US-002, e stesso rischio
        // di regressione: il predefinito vale alla creazione, non per sempre.
        $first = WorkflowTemplate::factory()->isDefault()->withSteps(2)->create();
        $project = $this->create()['project'];

        $second = WorkflowTemplate::factory()->withSteps(2)->create();
        app(SetDefaultWorkflowTemplate::class)->handle($second);

        $this->assertSame($first->id, $project->fresh()->workflow_template_id);
    }

    public function test_an_associated_template_cannot_be_deleted_from_the_database(): void
    {
        $project = Project::factory()->withTemplate()->create();

        $this->expectException(QueryException::class);

        DB::table('workflow_templates')->where('id', $project->workflow_template_id)->delete();
    }

    public function test_uncovered_roles_name_exactly_the_missing_ones(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $roles = Role::factory()->count(5)->create();

        foreach ($roles as $role) {
            StepDefinition::factory()->for($template)->for($role)->create();
        }

        $project = Project::factory()->withTemplate($template)->create();

        foreach ($roles->take(4) as $role) {
            ProjectRoleAssignment::factory()->for($project)->for($role)->create();
        }

        $uncovered = $project->uncoveredRoles();

        $this->assertSame([$roles[4]->name], $uncovered->pluck('name')->all());
    }

    public function test_a_fully_mapped_project_has_no_uncovered_roles(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $role = Role::factory()->create();
        StepDefinition::factory()->for($template)->for($role)->create();

        $project = Project::factory()->withTemplate($template)->create();
        ProjectRoleAssignment::factory()->for($project)->for($role)->create();

        $this->assertTrue($project->uncoveredRoles()->isEmpty());
    }

    public function test_a_deactivated_but_assigned_role_is_not_uncovered(): void
    {
        // Il ruolo e disattivato ma qualcuno lo ricopre: lo step ha un
        // responsabile, quindi non e una lacuna.
        $template = WorkflowTemplate::factory()->create();
        $role = Role::factory()->inactive()->create();
        StepDefinition::factory()->for($template)->for($role)->create();

        $project = Project::factory()->withTemplate($template)->create();
        ProjectRoleAssignment::factory()->for($project)->for($role)->create();

        $this->assertTrue($project->uncoveredRoles()->isEmpty());
    }

    public function test_a_project_without_a_template_has_no_uncovered_roles(): void
    {
        $this->assertTrue(Project::factory()->create()->uncoveredRoles()->isEmpty());
    }

    public function test_the_same_role_required_by_two_steps_is_reported_once(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $role = Role::factory()->create();

        StepDefinition::factory()->count(2)->for($template)->for($role)->create();

        $project = Project::factory()->withTemplate($template)->create();

        $this->assertCount(1, $project->uncoveredRoles());
    }

    public function test_uncovered_roles_does_not_query_once_the_relations_are_preloaded(): void
    {
        $template = WorkflowTemplate::factory()->create();
        StepDefinition::factory()->count(3)->for($template)->create();

        Project::factory()->count(5)->withTemplate($template)->create();

        $projects = Project::query()
            ->with(['workflowTemplate.stepDefinitions.role:id,name', 'assignments:id,project_id,role_id'])
            ->get();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach ($projects as $project) {
            $this->assertCount(3, $project->uncoveredRoles());
        }

        $this->assertSame(0, $queries, "Eseguite {$queries} query: `uncoveredRoles()` interroga per riga.");
    }
}
