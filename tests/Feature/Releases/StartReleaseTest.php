<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\StartRelease;
use App\Enums\ReleaseEventAction;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\InactiveProjectCannotStartRelease;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\ProjectWithoutUsableTemplate;
use App\Exceptions\RolesWithoutResponsible;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Criteri di accettazione dell'avvio: cosa viene copiato, chi risulta
 * responsabile, e ogni precondizione che deve fermare l'avvio **prima** di
 * scrivere qualsiasi riga.
 */
class StartReleaseTest extends TestCase
{
    use RefreshDatabase;

    private StartRelease $action;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(StartRelease::class);
        $this->actor = User::factory()->admin()->create();
    }

    public function test_it_copies_steps_and_fields_from_the_template(): void
    {
        $project = $this->projectReadyToRelease();
        $definitions = $project->workflowTemplate->stepDefinitions;

        $release = $this->action->handle($project, 'v2.4.0', $this->actor);

        $steps = $release->steps()->get();

        $this->assertCount($definitions->count(), $steps);

        foreach ($definitions as $index => $definition) {
            $step = $steps[$index];

            $this->assertSame($index + 1, $step->position);
            $this->assertSame($definition->name, $step->name);
            $this->assertSame($definition->instructions, $step->instructions);
            $this->assertSame($definition->role_id, $step->role_id);
            $this->assertSame($definition->role->name, $step->role_name);

            $fields = $step->fields;
            $sources = $definition->fieldDefinitions;

            $this->assertCount($sources->count(), $fields);

            foreach ($sources as $fieldIndex => $source) {
                $field = $fields[$fieldIndex];

                $this->assertSame($fieldIndex + 1, $field->position);
                $this->assertSame($source->label, $field->label);
                $this->assertSame($source->type, $field->type);
                $this->assertSame($source->is_required, $field->is_required);
                $this->assertSame($source->help_text, $field->help_text);
                $this->assertNull($field->value);
            }
        }
    }

    public function test_every_step_records_the_member_resolved_from_the_project_mapping(): void
    {
        $project = $this->projectReadyToRelease();

        $expected = $project->assignments->pluck('user_id', 'role_id');

        $release = $this->action->handle($project, 'v2.4.0', $this->actor);

        foreach ($release->steps()->get() as $step) {
            $this->assertSame($expected[$step->role_id], $step->assigned_user_id);
        }
    }

    public function test_the_first_step_is_active_and_all_the_others_are_blocked(): void
    {
        $project = $this->projectReadyToRelease();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor);

        $steps = $release->steps()->get();

        $this->assertSame(ReleaseStepStatus::Active, $steps->first()->status);
        $this->assertSame(
            1,
            $steps->where('status', ReleaseStepStatus::Active)->count(),
            'Una release deve avere esattamente uno step attivo.'
        );

        foreach ($steps->skip(1) as $step) {
            $this->assertSame(ReleaseStepStatus::Blocked, $step->status);
        }
    }

    public function test_the_release_starts_in_progress_with_its_author_and_instant(): void
    {
        $project = $this->projectReadyToRelease();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor)->fresh();

        $this->assertSame(ReleaseStatus::InProgress, $release->status);
        $this->assertSame($this->actor->id, $release->started_by);
        $this->assertTrue($release->started_at->between(now()->subMinute(), now()));
        $this->assertNull($release->completed_at);
        $this->assertSame($project->workflow_template_id, $release->workflow_template_id);
    }

    public function test_the_start_writes_a_single_event_in_the_register(): void
    {
        $project = $this->projectReadyToRelease();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor);

        $events = ReleaseEvent::where('release_id', $release->id)->get();

        $this->assertCount(1, $events);

        $event = $events->first();

        $this->assertSame(ReleaseEventAction::ReleaseStarted, $event->action);
        $this->assertSame($this->actor->id, $event->user_id);
        $this->assertNull($event->release_step_id);
        $this->assertTrue($event->created_at->between(now()->subMinute(), now()));
        $this->assertSame('v2.4.0', $event->payload['label']);
        $this->assertSame($project->workflowTemplate->name, $event->payload['template']);
        $this->assertSame($release->steps()->count(), $event->payload['steps']);
    }

    public function test_a_project_without_a_template_cannot_start_a_release(): void
    {
        $project = Project::factory()->create();

        $this->assertRefusedWithoutWriting(
            ProjectWithoutUsableTemplate::class,
            fn () => $this->action->handle($project, 'v2.4.0', $this->actor),
            'non ha un processo di rilascio associato'
        );
    }

    public function test_a_template_without_steps_cannot_start_a_release(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $project = Project::factory()->withTemplate($template)->create();

        $this->assertRefusedWithoutWriting(
            ProjectWithoutUsableTemplate::class,
            fn () => $this->action->handle($project, 'v2.4.0', $this->actor)
        );

        $this->assertSame(
            'templates.unusable_without_steps',
            $this->refusalReason(fn () => $this->action->handle($project, 'v2.4.0', $this->actor))
        );
    }

    public function test_an_inactive_template_cannot_start_a_release(): void
    {
        $project = $this->projectReadyToRelease();
        $project->workflowTemplate->update(['is_active' => false]);

        $this->assertRefusedWithoutWriting(
            ProjectWithoutUsableTemplate::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor)
        );

        $this->assertSame(
            'templates.unusable_inactive',
            $this->refusalReason(fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor))
        );
    }

    public function test_a_role_without_a_responsible_blocks_the_start_and_is_named(): void
    {
        $project = $this->projectReadyToRelease();

        $orphanRole = Role::query()->whereKey(
            $project->workflowTemplate->stepDefinitions->first()->role_id
        )->first();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->where('role_id', $orphanRole->id)
            ->delete();

        $this->assertRefusedWithoutWriting(
            RolesWithoutResponsible::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor),
            $orphanRole->name
        );
    }

    public function test_an_inactive_responsible_blocks_the_start_and_is_named(): void
    {
        $project = $this->projectReadyToRelease();

        $responsible = User::query()->whereKey($project->assignments->first()->user_id)->first();
        $responsible->update(['is_active' => false]);

        $this->assertRefusedWithoutWriting(
            InactiveResponsibleOnProject::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor),
            $responsible->name
        );
    }

    public function test_an_inactive_project_cannot_start_a_release(): void
    {
        $project = $this->projectReadyToRelease();
        $project->update(['is_active' => false]);

        $this->assertRefusedWithoutWriting(
            InactiveProjectCannotStartRelease::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor),
            'disattivato'
        );
    }

    public function test_the_same_label_cannot_be_used_twice_on_the_same_project(): void
    {
        $project = $this->projectReadyToRelease();

        $this->action->handle($project, 'v2.4.0', $this->actor);

        $this->expectException(QueryException::class);

        $this->action->handle($project->fresh(), 'v2.4.0', $this->actor);
    }

    public function test_a_refused_duplicate_label_leaves_no_partial_snapshot(): void
    {
        $project = $this->projectReadyToRelease();

        $this->action->handle($project, 'v2.4.0', $this->actor);

        $steps = ReleaseStep::count();
        $fields = ReleaseStepField::count();
        $events = ReleaseEvent::count();

        try {
            $this->action->handle($project->fresh(), 'v2.4.0', $this->actor);
        } catch (QueryException) {
            // Il rifiuto e atteso: qui interessa che la transazione non lasci nulla.
        }

        $this->assertSame(1, Release::count());
        $this->assertSame($steps, ReleaseStep::count());
        $this->assertSame($fields, ReleaseStepField::count());
        $this->assertSame($events, ReleaseEvent::count());
    }

    public function test_the_same_label_is_allowed_on_another_project(): void
    {
        $first = $this->projectReadyToRelease();
        $second = $this->projectReadyToRelease();

        $this->action->handle($first, 'v2.4.0', $this->actor);
        $release = $this->action->handle($second, 'v2.4.0', $this->actor);

        $this->assertSame('v2.4.0', $release->label);
        $this->assertSame(2, Release::count());
    }

    /**
     * Progetto pronto a rilasciare: processo utilizzabile con tre step, campi
     * su ciascuno, e un responsabile per ogni ruolo previsto.
     *
     * Uno dei ruoli e volutamente usato da due step: il cumulo di ruoli e la
     * norma su un team piccolo, e uno snapshot che non lo reggesse sarebbe
     * inutile (vedi `.ai/rules/models.md`).
     */
    private function projectReadyToRelease(): Project
    {
        $template = WorkflowTemplate::factory()->create();

        $roles = Role::factory()->count(2)->create();

        foreach ([$roles[0], $roles[1], $roles[0]] as $position => $role) {
            $step = StepDefinition::factory()->for($template)->create([
                'position' => $position + 1,
                'role_id' => $role->id,
            ]);

            FieldDefinition::factory()->count(2)->for($step)->create();
        }

        $project = Project::factory()->withTemplate($template)->create();

        foreach ($roles as $role) {
            ProjectRoleAssignment::factory()->create([
                'project_id' => $project->id,
                'role_id' => $role->id,
                'user_id' => User::factory()->create()->id,
            ]);
        }

        return $project->fresh();
    }

    /**
     * Verifica che il rifiuto arrivi prima di qualsiasi scrittura: una release a
     * meta sarebbe peggio di un rifiuto, perche resterebbe in giro.
     *
     * @param  class-string<Throwable>  $exception
     */
    private function assertRefusedWithoutWriting(string $exception, callable $start, ?string $mentions = null): void
    {
        try {
            $start();

            $this->fail("Atteso [{$exception}], nessuna eccezione sollevata.");
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);

            if ($mentions !== null) {
                $this->assertStringContainsString($mentions, $thrown->getMessage());
            }
        }

        $this->assertSame(0, Release::count());
        $this->assertSame(0, ReleaseStep::count());
        $this->assertSame(0, ReleaseStepField::count());
        $this->assertSame(0, ReleaseEvent::count());
    }

    /**
     * Chiave di traduzione del motivo per cui il processo non e utilizzabile.
     */
    private function refusalReason(callable $start): string
    {
        try {
            $start();
        } catch (ProjectWithoutUsableTemplate $refused) {
            return $refused->reasonKey;
        }

        $this->fail('Atteso un rifiuto con motivo, nessuna eccezione sollevata.');
    }
}
