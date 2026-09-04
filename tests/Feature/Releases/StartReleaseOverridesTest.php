<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\StartRelease;
use App\Enums\ReleaseEventAction;
use App\Exceptions\InactiveProjectCannotStartRelease;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\ProjectWithoutUsableTemplate;
use App\Exceptions\ResponsibleOverrideRefused;
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
use Tests\TestCase;
use Throwable;

/**
 * Responsabili indicati in avvio (US-013, FR-029), verificati dove la decisione
 * vive davvero: l'Action.
 *
 * Il caso portante **non** e l'override: e l'avvio **senza** override, che deve
 * restare osservabilmente identico a quello di prima della spec. Se quel caso
 * passa, la nuova firma e neutra — ed e la ragione per cui i test di US-004 non
 * hanno avuto bisogno di essere riscritti.
 */
class StartReleaseOverridesTest extends TestCase
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

    public function test_starting_without_overrides_is_indistinguishable_from_the_previous_behaviour(): void
    {
        $project = $this->projectReadyToRelease();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor);

        $steps = $release->steps()->ordered()->get();
        $byRole = $project->assignments->keyBy('role_id');

        $this->assertCount(3, $steps);

        foreach ($steps as $step) {
            $this->assertSame($byRole[$step->role_id]->user_id, $step->assigned_user_id);
            $this->assertFalse($step->responsible_overridden);
        }

        $event = ReleaseEvent::query()
            ->where('release_id', $release->id)
            ->where('action', ReleaseEventAction::ReleaseStarted)
            ->sole();

        // Chiave **assente**, non vuota: una release senza sovrascritture non deve
        // aggiungere rumore a un registro che e in sola aggiunta.
        $this->assertArrayNotHasKey('overrides', $event->payload);
        $this->assertSame(
            ['label' => 'v2.4.0', 'template' => $project->workflowTemplate->name, 'steps' => 3],
            $event->payload
        );
    }

    public function test_an_override_covers_every_step_of_that_role_and_leaves_the_others_alone(): void
    {
        $project = $this->projectReadyToRelease();

        // Il primo ruolo e usato da **due** step: e il caso che dimostra la
        // granularita per ruolo dichiarata dalla spec.
        [$shared, $other] = $this->rolesOf($project);
        $stand = User::factory()->create();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $shared->id => $stand->id,
        ]);

        $overriddenSteps = $release->steps()->where('role_id', $shared->id)->get();

        $this->assertCount(2, $overriddenSteps);

        foreach ($overriddenSteps as $step) {
            $this->assertSame($stand->id, $step->assigned_user_id);
            $this->assertTrue($step->responsible_overridden);
        }

        $untouched = $release->steps()->where('role_id', $other->id)->sole();

        $this->assertSame(
            $project->assignments->firstWhere('role_id', $other->id)->user_id,
            $untouched->assigned_user_id
        );
        $this->assertFalse($untouched->responsible_overridden);
    }

    public function test_choosing_the_default_person_is_not_an_override(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared] = $this->rolesOf($project);
        $default = $project->assignments->firstWhere('role_id', $shared->id)->user_id;

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $shared->id => $default,
        ]);

        foreach ($release->steps as $step) {
            $this->assertFalse(
                $step->responsible_overridden,
                'Indicare la persona gia predefinita non e una sovrascrittura: segnalarla direbbe una cosa falsa.'
            );
        }

        $this->assertArrayNotHasKey('overrides', $this->startedEvent($release)->payload);
    }

    public function test_an_override_covers_a_role_left_without_a_responsible(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared] = $this->rolesOf($project);
        ProjectRoleAssignment::query()
            ->where('project_id', $project->id)
            ->where('role_id', $shared->id)
            ->delete();

        $stand = User::factory()->create();

        $release = $this->action->handle($project->fresh(), 'v2.4.0', $this->actor, [
            $shared->id => $stand->id,
        ]);

        foreach ($release->steps()->where('role_id', $shared->id)->get() as $step) {
            $this->assertSame($stand->id, $step->assigned_user_id);
            // Non c'era alcun predefinito da cui ereditare: la persona e stata
            // indicata in avvio, e lo snapshot lo dichiara.
            $this->assertTrue($step->responsible_overridden);
        }

        $overrides = $this->startedEvent($release)->payload['overrides'];

        $this->assertCount(1, $overrides);
        $this->assertSame($shared->name, $overrides[0]['role']);
        $this->assertNull($overrides[0]['from']);
        $this->assertSame($stand->name, $overrides[0]['to']);
    }

    public function test_a_role_left_uncovered_is_refused_with_its_name_and_its_identifier(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared] = $this->rolesOf($project);
        ProjectRoleAssignment::query()
            ->where('project_id', $project->id)
            ->where('role_id', $shared->id)
            ->delete();

        $refused = $this->assertRefusedWithoutWriting(
            RolesWithoutResponsible::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor),
            $shared->name
        );

        $this->assertInstanceOf(RolesWithoutResponsible::class, $refused);
        // Gli identificativi affiancano i nomi: senza, la schermata potrebbe solo
        // mettere il rifiuto in testa invece che accanto al selettore da correggere.
        $this->assertSame([$shared->id], $refused->roleIds);
        $this->assertSame([$shared->name], $refused->roleNames);
    }

    public function test_an_inactive_person_indicated_at_start_is_refused_with_the_reason_indexed_by_role(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared] = $this->rolesOf($project);
        $inactive = User::factory()->create(['is_active' => false]);

        $refused = $this->assertRefusedWithoutWriting(
            ResponsibleOverrideRefused::class,
            fn () => $this->action->handle($project, 'v2.4.0', $this->actor, [$shared->id => $inactive->id]),
            $shared->name
        );

        $this->assertInstanceOf(ResponsibleOverrideRefused::class, $refused);
        $this->assertSame(
            [$shared->id => 'releases.override_refused_inactive_member'],
            $refused->reasonsByRole
        );
        $this->assertNull($refused->headKey);
    }

    public function test_a_person_that_does_not_exist_is_refused_with_the_reason_indexed_by_role(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared] = $this->rolesOf($project);

        $refused = $this->assertRefusedWithoutWriting(
            ResponsibleOverrideRefused::class,
            fn () => $this->action->handle($project, 'v2.4.0', $this->actor, [
                $shared->id => '01a06bca-0000-7000-8000-000000000000',
            ]),
            $shared->name
        );

        $this->assertInstanceOf(ResponsibleOverrideRefused::class, $refused);
        $this->assertSame(
            [$shared->id => 'releases.override_refused_missing_member'],
            $refused->reasonsByRole
        );
    }

    public function test_a_role_the_process_does_not_use_is_refused(): void
    {
        $project = $this->projectReadyToRelease();

        // Non arriva dal modulo, che filtra le chiavi ammesse: arriva da un client
        // che ha costruito la richiesta a mano.
        $foreign = Role::factory()->create();

        $refused = $this->assertRefusedWithoutWriting(
            ResponsibleOverrideRefused::class,
            fn () => $this->action->handle($project, 'v2.4.0', $this->actor, [
                $foreign->id => User::factory()->create()->id,
            ])
        );

        $this->assertInstanceOf(ResponsibleOverrideRefused::class, $refused);
        // Nessun campo a cui agganciarsi nel modulo: il messaggio va in testa.
        $this->assertSame([], $refused->reasonsByRole);
        $this->assertSame('releases.override_refused_role_not_in_process', $refused->headKey);
    }

    /**
     * L'ordine dei controlli non e un dettaglio: un override invalido che lasciasse
     * anche un ruolo scoperto deve riportare la causa vera — la scelta appena
     * fatta — e non quella derivata, che sparirebbe da sola correggendo la prima.
     */
    public function test_an_invalid_choice_is_reported_before_the_coverage_it_would_have_left_broken(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared, $other] = $this->rolesOf($project);

        ProjectRoleAssignment::query()
            ->where('project_id', $project->id)
            ->where('role_id', $other->id)
            ->delete();

        $inactive = User::factory()->create(['is_active' => false]);

        $this->assertRefusedWithoutWriting(
            ResponsibleOverrideRefused::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor, [
                $shared->id => $inactive->id,
            ])
        );
    }

    public function test_an_override_never_writes_the_project_or_the_team_default_mapping(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared] = $this->rolesOf($project);
        DefaultRoleAssignment::factory()->create([
            'role_id' => $shared->id,
            'user_id' => $project->assignments->firstWhere('role_id', $shared->id)->user_id,
        ]);

        $projectMapping = $this->mappingSnapshot();
        $teamMapping = $this->defaultMappingSnapshot();

        $this->action->handle($project, 'v2.4.0', $this->actor, [
            $shared->id => User::factory()->create()->id,
        ]);

        // Confronto riga per riga e non solo di conteggio: sostituire una persona
        // lasciando lo stesso numero di righe e proprio il modo in cui la mappatura
        // si romperebbe senza farsi notare.
        $this->assertSame($projectMapping, $this->mappingSnapshot());
        $this->assertSame($teamMapping, $this->defaultMappingSnapshot());
    }

    public function test_an_override_on_one_release_does_not_reach_the_next_one(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared] = $this->rolesOf($project);
        $stand = User::factory()->create();
        $default = $project->assignments->firstWhere('role_id', $shared->id)->user_id;

        $this->action->handle($project, 'v2.4.0', $this->actor, [$shared->id => $stand->id]);
        $second = $this->action->handle($project->fresh(), 'v2.4.1', $this->actor);

        foreach ($second->steps()->where('role_id', $shared->id)->get() as $step) {
            $this->assertSame($default, $step->assigned_user_id);
            $this->assertFalse($step->responsible_overridden);
        }
    }

    public function test_the_rules_that_no_override_can_cure_still_refuse(): void
    {
        [$shared] = $this->rolesOf($inactiveProject = $this->projectReadyToRelease());
        $inactiveProject->update(['is_active' => false]);

        $this->assertRefusedWithoutWriting(
            InactiveProjectCannotStartRelease::class,
            fn () => $this->action->handle($inactiveProject->fresh(), 'v2.4.0', $this->actor, [
                $shared->id => User::factory()->create()->id,
            ])
        );

        $withoutTemplate = Project::factory()->create();

        $this->assertRefusedWithoutWriting(
            ProjectWithoutUsableTemplate::class,
            fn () => $this->action->handle($withoutTemplate, 'v2.4.0', $this->actor, [
                $shared->id => User::factory()->create()->id,
            ])
        );

        $emptyTemplate = Project::factory()->withTemplate(WorkflowTemplate::factory()->create())->create();

        $this->assertRefusedWithoutWriting(
            ProjectWithoutUsableTemplate::class,
            fn () => $this->action->handle($emptyTemplate, 'v2.4.0', $this->actor, [
                $shared->id => User::factory()->create()->id,
            ])
        );

        $disabledTemplate = Project::factory()
            ->withTemplate($this->usableTemplate())
            ->create();
        $disabledTemplate->workflowTemplate->update(['is_active' => false]);

        $this->assertRefusedWithoutWriting(
            ProjectWithoutUsableTemplate::class,
            fn () => $this->action->handle($disabledTemplate->fresh(), 'v2.4.0', $this->actor, [
                $shared->id => User::factory()->create()->id,
            ])
        );
    }

    public function test_an_inactive_default_responsible_still_refuses_when_it_is_not_overridden(): void
    {
        $project = $this->projectReadyToRelease();

        [, $other] = $this->rolesOf($project);
        $responsible = User::query()
            ->whereKey($project->assignments->firstWhere('role_id', $other->id)->user_id)
            ->firstOrFail();
        $responsible->update(['is_active' => false]);

        $this->assertRefusedWithoutWriting(
            InactiveResponsibleOnProject::class,
            fn () => $this->action->handle($project->fresh(), 'v2.4.0', $this->actor),
            $responsible->name
        );
    }

    public function test_the_started_event_records_only_the_roles_actually_overridden(): void
    {
        $project = $this->projectReadyToRelease();

        [$shared, $other] = $this->rolesOf($project);
        $stand = User::factory()->create();
        $from = User::query()
            ->whereKey($project->assignments->firstWhere('role_id', $shared->id)->user_id)
            ->firstOrFail();

        $release = $this->action->handle($project, 'v2.4.0', $this->actor, [
            $shared->id => $stand->id,
            // Lasciato sul predefinito: non deve comparire nel payload.
            $other->id => $project->assignments->firstWhere('role_id', $other->id)->user_id,
        ]);

        $this->assertSame(
            1,
            ReleaseEvent::query()
                ->where('release_id', $release->id)
                ->where('action', ReleaseEventAction::ReleaseStarted)
                ->count()
        );

        $this->assertSame(
            [['role' => $shared->name, 'from' => $from->name, 'to' => $stand->name]],
            $this->startedEvent($release)->payload['overrides']
        );
    }

    /**
     * Ruoli del processo, nell'ordine degli step: il primo e usato da due step.
     *
     * @return list<Role>
     */
    private function rolesOf(Project $project): array
    {
        return $project->workflowTemplate->stepDefinitions
            ->unique('role_id')
            ->pluck('role')
            ->values()
            ->all();
    }

    private function startedEvent(Release $release): ReleaseEvent
    {
        return ReleaseEvent::query()
            ->where('release_id', $release->id)
            ->where('action', ReleaseEventAction::ReleaseStarted)
            ->sole();
    }

    /**
     * Mappatura di progetto, riga per riga e in ordine stabile.
     *
     * @return list<array{project_id: string, role_id: string, user_id: string}>
     */
    private function mappingSnapshot(): array
    {
        return ProjectRoleAssignment::query()
            ->orderBy('project_id')
            ->orderBy('role_id')
            ->get(['project_id', 'role_id', 'user_id'])
            ->map(fn (ProjectRoleAssignment $assignment): array => [
                'project_id' => $assignment->project_id,
                'role_id' => $assignment->role_id,
                'user_id' => $assignment->user_id,
            ])
            ->all();
    }

    /**
     * @return list<array{role_id: string, user_id: string}>
     */
    private function defaultMappingSnapshot(): array
    {
        return DefaultRoleAssignment::query()
            ->orderBy('role_id')
            ->get(['role_id', 'user_id'])
            ->map(fn (DefaultRoleAssignment $assignment): array => [
                'role_id' => $assignment->role_id,
                'user_id' => $assignment->user_id,
            ])
            ->all();
    }

    /**
     * Processo utilizzabile: tre step su due ruoli, il primo usato due volte.
     */
    private function usableTemplate(): WorkflowTemplate
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

        return $template;
    }

    /**
     * Progetto pronto a rilasciare, nella stessa forma usata da `StartReleaseTest`:
     * un ruolo e volutamente usato da due step, ed e cio che rende dimostrabile la
     * granularita per ruolo.
     */
    private function projectReadyToRelease(): Project
    {
        $template = $this->usableTemplate();
        $project = Project::factory()->withTemplate($template)->create();

        foreach ($template->stepDefinitions->pluck('role_id')->unique() as $roleId) {
            ProjectRoleAssignment::factory()->create([
                'project_id' => $project->id,
                'role_id' => $roleId,
                'user_id' => User::factory()->create()->id,
            ]);
        }

        return $project->fresh();
    }

    /**
     * Verifica che il rifiuto arrivi prima di qualsiasi scrittura e torna
     * l'eccezione, per le asserzioni che riguardano cosa porta con se.
     *
     * @param  class-string<Throwable>  $exception
     */
    private function assertRefusedWithoutWriting(string $exception, callable $start, ?string $mentions = null): Throwable
    {
        $thrown = null;

        try {
            $start();
        } catch (Throwable $caught) {
            // Il `fail()` non puo stare dentro il `try`: solleva a sua volta, e il
            // `catch` lo inghiottirebbe riportando un errore che non e quello vero.
            $thrown = $caught;
        }

        $this->assertNotNull($thrown, "Atteso [{$exception}], nessuna eccezione sollevata.");
        $this->assertInstanceOf($exception, $thrown);

        if ($mentions !== null) {
            $this->assertStringContainsString($mentions, $thrown->getMessage());
        }

        $this->assertSame(0, Release::count());
        $this->assertSame(0, ReleaseStep::count());
        $this->assertSame(0, ReleaseStepField::count());
        $this->assertSame(0, ReleaseEvent::count());

        return $thrown;
    }
}
