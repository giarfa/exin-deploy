<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\StartRelease;
use App\Enums\ReleaseEventAction;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\RolesWithoutResponsible;
use App\Models\DefaultRoleAssignment;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Avvio con un responsabile diverso da quello risolto dalla mappatura di
 * progetto, per la singola release.
 *
 * Il percorso **senza** override resta in `StartReleaseTest`, che questa spec non
 * tocca: quel file e la prova che il parametro con default vuoto conserva il
 * comportamento di prima, e mescolarci gli scenari nuovi la annullerebbe.
 *
 * Il criterio che tutti gli altri servono e l'ultimo: l'override e un effetto
 * one-shot. Se una sola riga di mappatura venisse toccata, la sostituzione decisa
 * per un'assenza di un giorno diventerebbe il default di ogni rilascio futuro.
 */
class StartReleaseOverrideTest extends TestCase
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

    public function test_an_override_freezes_the_step_with_the_chosen_member(): void
    {
        $project = $this->projectReadyToRelease();

        $role = $this->roles($project)->first();
        $projectResponsible = $project->assignments->firstWhere('role_id', $role->id)->user_id;
        $substitute = User::factory()->create();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $role->id => $substitute->id,
        ]);

        $steps = $release->steps()->where('role_id', $role->id)->get();

        $this->assertTrue($steps->isNotEmpty(), 'Il ruolo sostituito deve comparire nella catena.');

        foreach ($steps as $step) {
            $this->assertSame($substitute->id, $step->assigned_user_id);
            $this->assertNotSame($projectResponsible, $step->assigned_user_id);
        }
    }

    public function test_the_roles_left_alone_keep_the_member_from_the_project_mapping(): void
    {
        $project = $this->projectReadyToRelease();

        $roles = $this->roles($project);
        $overridden = $roles->first();
        $untouched = $roles->last();

        $expected = $project->assignments->firstWhere('role_id', $untouched->id)->user_id;

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $overridden->id => User::factory()->create()->id,
        ]);

        foreach ($release->steps()->where('role_id', $untouched->id)->get() as $step) {
            $this->assertSame($expected, $step->assigned_user_id);
        }
    }

    public function test_a_role_without_a_project_responsible_is_covered_by_a_valid_override(): void
    {
        $project = $this->projectReadyToRelease();

        $orphan = $this->roles($project)->first();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->where('role_id', $orphan->id)
            ->delete();

        $substitute = User::factory()->create();

        $release = $this->action->handle($project->fresh(), 'v2.4.0', $this->actor, [
            $orphan->id => $substitute->id,
        ]);

        $this->assertSame(1, Release::count());

        foreach ($release->steps()->where('role_id', $orphan->id)->get() as $step) {
            $this->assertSame($substitute->id, $step->assigned_user_id);
        }
    }

    public function test_an_inactive_project_responsible_replaced_by_an_active_member_no_longer_blocks(): void
    {
        $project = $this->projectReadyToRelease();

        $role = $this->roles($project)->first();
        $responsible = User::query()->whereKey(
            $project->assignments->firstWhere('role_id', $role->id)->user_id
        )->first();
        $responsible->update(['is_active' => false]);

        $substitute = User::factory()->create();

        $release = $this->action->handle($project->fresh(), 'v2.4.0', $this->actor, [
            $role->id => $substitute->id,
        ]);

        $this->assertSame(1, Release::count());

        foreach ($release->steps()->where('role_id', $role->id)->get() as $step) {
            $this->assertSame($substitute->id, $step->assigned_user_id);
        }
    }

    public function test_an_override_towards_an_inactive_member_is_refused_and_the_person_is_named(): void
    {
        $project = $this->projectReadyToRelease();

        $role = $this->roles($project)->first();
        $inactive = User::factory()->create(['is_active' => false]);

        // Lo stesso errore dei responsabili disattivati di progetto: il problema e
        // identico — uno step in carico a chi non accede piu — e la soluzione anche.
        $this->assertRefusedWithoutWriting(
            InactiveResponsibleOnProject::class,
            fn () => $this->action->handle($project, 'v2.4.0', $this->actor, [$role->id => $inactive->id]),
            $inactive->name
        );
    }

    public function test_the_uncovered_roles_exception_names_only_the_ones_still_missing(): void
    {
        $project = $this->projectReadyToRelease();

        $roles = $this->roles($project);
        $covered = $roles->first();
        $stillMissing = $roles->last();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->whereIn('role_id', [$covered->id, $stillMissing->id])
            ->delete();

        $thrown = $this->refusal(
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor, [
                $covered->id => User::factory()->create()->id,
            ])
        );

        $this->assertInstanceOf(RolesWithoutResponsible::class, $thrown);
        $this->assertSame([$stillMissing->name], $thrown->roleNames);
        $this->assertStringNotContainsString($covered->name, $thrown->getMessage());
    }

    public function test_the_inactive_responsibles_exception_ignores_the_ones_replaced_by_an_override(): void
    {
        $project = $this->projectReadyToRelease();

        $roles = $this->roles($project);

        $replaced = User::query()->whereKey(
            $project->assignments->firstWhere('role_id', $roles->first()->id)->user_id
        )->first();
        $replaced->update(['is_active' => false]);

        $stillInactive = User::query()->whereKey(
            $project->assignments->firstWhere('role_id', $roles->last()->id)->user_id
        )->first();
        $stillInactive->update(['is_active' => false]);

        $thrown = $this->refusal(
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor, [
                $roles->first()->id => User::factory()->create()->id,
            ])
        );

        $this->assertInstanceOf(InactiveResponsibleOnProject::class, $thrown);
        $this->assertSame([$stillInactive->name], $thrown->memberNames);
        $this->assertStringNotContainsString($replaced->name, $thrown->getMessage());
    }

    public function test_an_override_for_a_role_outside_the_process_is_ignored(): void
    {
        $project = $this->projectReadyToRelease();

        $stranger = Role::factory()->create();
        $expected = $project->assignments->pluck('user_id', 'role_id');

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $stranger->id => User::factory()->create()->id,
        ]);

        foreach ($release->steps()->get() as $step) {
            $this->assertSame($expected[$step->role_id], $step->assigned_user_id);
        }
    }

    public function test_an_override_towards_an_unknown_member_does_not_count_as_one(): void
    {
        /*
         * Un identificativo che non risolve non e una copertura: trattarlo come tale
         * darebbe uno step assegnato a nessuno. Il ruolo torna a dipendere dalla
         * mappatura di progetto, e se e scoperto l'avvio viene rifiutato.
         */
        $project = $this->projectReadyToRelease();

        $orphan = $this->roles($project)->first();

        ProjectRoleAssignment::where('project_id', $project->id)
            ->where('role_id', $orphan->id)
            ->delete();

        $this->assertRefusedWithoutWriting(
            RolesWithoutResponsible::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor, [
                $orphan->id => (string) Str::uuid7(),
            ]),
            $orphan->name
        );
    }

    public function test_an_override_on_a_covered_role_with_an_unknown_member_falls_back_to_the_project_mapping(): void
    {
        $project = $this->projectReadyToRelease();

        $role = $this->roles($project)->first();
        $expected = $project->assignments->firstWhere('role_id', $role->id)->user_id;

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $role->id => (string) Str::uuid7(),
        ]);

        foreach ($release->steps()->where('role_id', $role->id)->get() as $step) {
            $this->assertSame($expected, $step->assigned_user_id);
        }
    }

    public function test_an_override_writes_nothing_on_the_two_mapping_tables(): void
    {
        $project = $this->projectReadyToRelease();

        $role = $this->roles($project)->first();
        $substitute = User::factory()->create();

        // I default di team esistono davvero in questo scenario: senza almeno una
        // riga il confronto piu sotto passerebbe su due insiemi vuoti, cioe non
        // proverebbe nulla.
        foreach ($this->roles($project) as $mapped) {
            DefaultRoleAssignment::factory()->create(['role_id' => $mapped->id]);
        }

        $projectMapping = ProjectRoleAssignment::query()
            ->orderBy('id')
            ->get(['id', 'project_id', 'role_id', 'user_id'])
            ->toArray();

        $defaultMapping = DefaultRoleAssignment::query()
            ->orderBy('id')
            ->get(['id', 'role_id', 'user_id'])
            ->toArray();

        $this->action->handle($project, 'v2.4.0', $this->actor, [$role->id => $substitute->id]);

        $this->assertSame(
            $projectMapping,
            ProjectRoleAssignment::query()->orderBy('id')->get(['id', 'project_id', 'role_id', 'user_id'])->toArray(),
            "L'override e un effetto sulla singola release: la mappatura di progetto non si tocca."
        );

        $this->assertSame(
            $defaultMapping,
            DefaultRoleAssignment::query()->orderBy('id')->get(['id', 'role_id', 'user_id'])->toArray(),
            "L'override non risale ai default di team."
        );
    }

    public function test_the_event_payload_keeps_no_trace_of_the_overridden_roles(): void
    {
        /*
         * Il registro e in sola aggiunta: cio che finisce nel payload ci resta per
         * sempre. Questa asserzione fissa la decisione di non arricchirlo con i ruoli
         * sostituiti — l'avvio e sempre lo stesso evento, e i responsabili congelati
         * sono gia leggibili sugli step.
         */
        $project = $this->projectReadyToRelease();

        $role = $this->roles($project)->first();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $role->id => User::factory()->create()->id,
        ]);

        $event = ReleaseEvent::where('release_id', $release->id)->sole();

        $this->assertSame(ReleaseEventAction::ReleaseStarted, $event->action);
        $this->assertSame(['label', 'template', 'steps'], array_keys($event->payload));
        $this->assertSame('v2.4.0', $event->payload['label']);
    }

    /**
     * Ruoli previsti dal processo del progetto, ordinati per posizione dello step.
     *
     * @return Collection<int, Role>
     */
    private function roles(Project $project)
    {
        return $project->workflowTemplate->stepDefinitions
            ->sortBy('position')
            ->pluck('role')
            ->unique('id')
            ->values();
    }

    /**
     * Progetto pronto a rilasciare, nella stessa forma della suite senza override:
     * due ruoli su tre step, con il primo ruolo ripetuto — il cumulo di ruoli e la
     * norma su un team piccolo (vedi `.ai/rules/models.md`), e un override deve
     * valere su **tutti** gli step di quel ruolo.
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
     * L'eccezione sollevata dall'avvio, per interrogarne i campi.
     */
    private function refusal(callable $start): Throwable
    {
        try {
            $start();
        } catch (Throwable $caught) {
            return $caught;
        }

        $this->fail('Atteso un rifiuto, nessuna eccezione sollevata.');
    }

    /**
     * Stessa forma della suite senza override: un rifiuto non lascia una release a
     * meta, e con essa uno step in carico a nessuno.
     *
     * @param  class-string<Throwable>  $exception
     */
    private function assertRefusedWithoutWriting(string $exception, callable $start, ?string $mentions = null): void
    {
        $thrown = $this->refusal($start);

        $this->assertInstanceOf($exception, $thrown);

        if ($mentions !== null) {
            $this->assertStringContainsString($mentions, $thrown->getMessage());
        }

        $this->assertSame(0, Release::count());
        $this->assertSame(0, ReleaseStep::count());
        $this->assertSame(0, ReleaseStepField::count());
        $this->assertSame(0, ReleaseEvent::count());
    }
}
