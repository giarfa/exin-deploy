<?php

namespace Tests\Feature\Releases;

use App\Enums\FieldType;
use App\Enums\ReleaseEventAction;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FR-012 lato interfaccia: chi puo compilare e chiudere, e cosa accade a chi non
 * puo.
 *
 * Il percorso che conta e quello che **salta** il middleware — l'invocazione diretta
 * dell'azione Livewire — perche la rotta di chiusura non porta un `->can()`: la
 * deroga e dichiarata, e serve a poter registrare il tentativo prima di rifiutarlo.
 * Se il controllo nel componente cadesse, sarebbe questo test a dirlo.
 */
class ReleaseStepPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_responsible_may_view_fill_and_close_the_active_step(): void
    {
        $step = $this->activeStep();
        $responsible = $step->assignedUser;

        $this->assertTrue(Gate::forUser($responsible)->allows('view', $step));
        $this->assertTrue(Gate::forUser($responsible)->allows('fill', $step));
        $this->assertTrue(Gate::forUser($responsible)->allows('close', $step));
    }

    public function test_an_administrator_may_act_on_a_step_that_is_not_theirs(): void
    {
        $step = $this->activeStep();

        // Un amministratore governa il processo di rilascio: puo intervenire per
        // sbloccare un flusso fermo su chi non e disponibile.
        $administrator = User::factory()->admin()->create();

        $this->assertTrue(Gate::forUser($administrator)->allows('view', $step));
        $this->assertTrue(Gate::forUser($administrator)->allows('fill', $step));
        $this->assertTrue(Gate::forUser($administrator)->allows('close', $step));
    }

    public function test_another_member_may_not_even_look_at_the_step(): void
    {
        $step = $this->activeStep();
        $other = User::factory()->member()->create();

        $this->assertFalse(Gate::forUser($other)->allows('view', $step));
        $this->assertFalse(Gate::forUser($other)->allows('fill', $step));
        $this->assertFalse(Gate::forUser($other)->allows('close', $step));
    }

    public function test_filling_and_closing_are_denied_on_a_blocked_step_even_to_an_administrator(): void
    {
        $release = $this->releaseInProgress();
        $blocked = $release->steps->get(1);

        $administrator = User::factory()->admin()->create();

        // Il vincolo dello step attivo vale anche per un amministratore: la catena
        // deve continuare a descrivere l'ordine in cui il rilascio e avvenuto.
        $this->assertFalse(Gate::forUser($administrator)->allows('fill', $blocked));
        $this->assertFalse(Gate::forUser($administrator)->allows('close', $blocked));

        // La consultazione resta aperta: chi ne risponde deve poter leggere cosa gli
        // sara chiesto.
        $this->assertTrue(Gate::forUser($blocked->assignedUser)->allows('view', $blocked));
    }

    public function test_filling_and_closing_are_denied_on_a_completed_step_even_to_an_administrator(): void
    {
        $step = $this->activeStep();
        $step->update([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $step->assigned_user_id,
            'completed_at' => now(),
        ]);

        $step = $step->fresh();
        $administrator = User::factory()->admin()->create();

        $this->assertFalse(Gate::forUser($administrator)->allows('fill', $step));
        $this->assertFalse(Gate::forUser($administrator)->allows('close', $step));
        $this->assertFalse(Gate::forUser($step->assignedUser)->allows('close', $step));
        $this->assertTrue(Gate::forUser($step->assignedUser)->allows('view', $step));
    }

    public function test_filling_and_closing_are_denied_on_a_completed_release_even_to_an_administrator(): void
    {
        $step = $this->activeStep();
        $step->release->update(['status' => ReleaseStatus::Completed]);

        $step = $step->fresh();

        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('close', $step));
        $this->assertFalse(Gate::forUser($step->assignedUser)->allows('fill', $step));
    }

    public function test_a_completed_release_is_read_only_for_everyone_administrators_included(): void
    {
        /*
         * La forma reale di una release conclusa: nessuno step attivo, tutti chiusi.
         * Il divieto non passa dal filtro `before()` — `fill` e `close` sono in
         * `NOT_FILTERED` proprio perche il vincolo valga anche per un
         * amministratore — e senza questa prova una revisione futura potrebbe
         * spostarle sotto il filtro senza accorgersi di cosa rompe.
         */
        $release = $this->completedRelease();
        $step = $release->steps->first();
        $administrator = User::factory()->admin()->create();

        $this->assertFalse(Gate::forUser($step->assignedUser)->allows('fill', $step));
        $this->assertFalse(Gate::forUser($step->assignedUser)->allows('close', $step));

        $this->assertFalse(Gate::forUser($administrator)->allows('fill', $step));
        $this->assertFalse(Gate::forUser($administrator)->allows('close', $step));

        /*
         * La consultazione resta invece consentita: lo storico si legge a tempo
         * indeterminato (AC 6), e negarla renderebbe illeggibile proprio cio che la
         * conclusione esiste per conservare.
         */
        $this->assertTrue(Gate::forUser($step->assignedUser)->allows('view', $step));
        $this->assertTrue(Gate::forUser($administrator)->allows('view', $step));
    }

    public function test_a_step_of_a_completed_release_refuses_the_closing_invoked_directly(): void
    {
        $release = $this->completedRelease();
        $step = $release->steps->first();

        $this->actingAs($step->assignedUser);

        Log::shouldReceive('warning')->once();

        /*
         * Il percorso che il middleware non copre: la rotta non porta un `->can()`
         * (deroga dichiarata al vincolo permanente 12), quindi l'unico controllo su
         * un'azione Livewire e quello dentro il componente. Il montaggio passa —
         * `view` e consentita — e cade l'azione.
         */
        Livewire::test('releases.step', ['releaseStep' => $step])
            ->call('close')
            ->assertForbidden();

        $event = ReleaseEvent::query()
            ->where('action', ReleaseEventAction::UnauthorizedAttempt)
            ->first();

        $this->assertNotNull($event, 'Il tentativo su una release conclusa non e finito nel registro.');
        $this->assertSame($step->id, $event->release_step_id);
        $this->assertSame('close', $event->payload['ability']);

        // Nulla e cambiato: la release resta conclusa come era.
        $this->assertSame(ReleaseStatus::Completed, $release->fresh()->status);
        $this->assertSame(ReleaseStepStatus::Completed, $step->fresh()->status);
    }

    public function test_reading_a_step_of_a_completed_release_leaves_no_attempt_in_the_register(): void
    {
        /*
         * L'altro lato della stessa moneta: la consultazione passa da `view`, che e
         * consentita, e non deve lasciare righe di tentativo. Senza questa prova, le
         * righe legittime del test precedente sembrerebbero un difetto a chi legge
         * il registro piu avanti.
         */
        $release = $this->completedRelease();
        $step = $release->steps->first();

        $this->actingAs($step->assignedUser);

        $this->get(route('releases.step', $step))->assertOk();

        $this->assertSame(
            0,
            ReleaseEvent::query()->where('action', ReleaseEventAction::UnauthorizedAttempt)->count()
        );
    }

    public function test_another_member_cannot_open_the_step_page(): void
    {
        $step = $this->activeStep();

        $this->actingAs(User::factory()->member()->create());

        $this->get(route('releases.step', $step))->assertForbidden();
    }

    public function test_an_unauthorized_closing_is_refused_recorded_and_logged(): void
    {
        $step = $this->activeStep();
        $intruder = User::factory()->member()->create();

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step]);

        /*
         * L'autorizzazione cade **dopo** che la pagina e stata aperta, ed e la
         * finestra che conta: le azioni Livewire non ripassano dal middleware della
         * rotta — che qui, deliberatamente, non porta nemmeno un `->can()` — quindi
         * l'unico controllo e quello dentro il componente.
         */
        Log::shouldReceive('warning')->once();

        $this->actingAs($intruder);

        $component->call('close')->assertForbidden();

        $event = ReleaseEvent::query()
            ->where('action', ReleaseEventAction::UnauthorizedAttempt)
            ->first();

        $this->assertNotNull($event, 'Il tentativo non autorizzato non e finito nel registro.');
        $this->assertSame($step->id, $event->release_step_id);
        $this->assertSame($intruder->id, $event->user_id);
        $this->assertSame('close', $event->payload['ability']);

        // Nessuna transizione: il rifiuto non ha fatto avanzare nulla.
        $this->assertSame(ReleaseStepStatus::Active, $step->fresh()->status);
    }

    public function test_an_unauthorized_draft_is_recorded_too(): void
    {
        $step = $this->activeStep();
        $intruder = User::factory()->member()->create();

        $this->actingAs($step->assignedUser);

        $component = Livewire::test('releases.step', ['releaseStep' => $step]);

        $this->actingAs($intruder);

        $component->call('save')->assertForbidden();

        $this->assertSame(
            1,
            ReleaseEvent::query()->where('action', ReleaseEventAction::UnauthorizedAttempt)->count()
        );
    }

    public function test_a_denied_read_is_logged_but_does_not_fill_the_register(): void
    {
        $step = $this->activeStep();

        Log::shouldReceive('warning')->once();

        $this->actingAs(User::factory()->member()->create());

        $this->get(route('releases.step', $step))->assertForbidden();

        // Il registro e in sola aggiunta e non cancellabile: un ricaricamento di
        // indirizzo lo gonfierebbe di righe che non dicono nulla sul rilascio.
        // FR-012 parla di compilare e chiudere.
        $this->assertSame(0, ReleaseEvent::count());
    }

    public function test_a_blocked_step_refuses_the_action_invoked_directly(): void
    {
        $release = $this->releaseInProgress();
        $blocked = $release->steps->get(1);

        $this->actingAs($blocked->assignedUser);

        // La schermata non offre il form, ma nascondere un comando non e
        // autorizzazione: l'azione invocata a mano viene comunque rifiutata.
        Livewire::test('releases.step', ['releaseStep' => $blocked])
            ->call('close')
            ->assertForbidden();

        $this->assertSame(ReleaseStepStatus::Blocked, $blocked->fresh()->status);
    }

    public function test_a_completed_step_refuses_the_action_invoked_directly(): void
    {
        $step = $this->activeStep();
        $step->update([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $step->assigned_user_id,
            'completed_at' => now(),
        ]);

        $this->actingAs($step->assignedUser);

        Livewire::test('releases.step', ['releaseStep' => $step->fresh()])
            ->call('save')
            ->assertForbidden();
    }

    public function test_the_entry_command_appears_only_for_who_the_policy_authorizes(): void
    {
        $project = $this->projectReadyToRelease();

        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors();

        $release = Release::query()->where('project_id', $project->id)->firstOrFail();
        $active = $release->steps()->where('status', ReleaseStepStatus::Active->value)->firstOrFail();

        // L'amministratore che ha avviato raggiunge lo step attivo dalla catena.
        $component->assertSee(__('releases.step_open_action'));
        $component->assertSee(route('releases.step', $active), escape: false);

        // Il responsabile dello step lo vede allo stesso modo; chi non e
        // autorizzato non trova il comando.
        $this->assertTrue(Gate::forUser($active->assignedUser)->allows('fill', $active));
        $this->assertFalse(Gate::forUser(User::factory()->member()->create())->allows('fill', $active));
    }

    /**
     * Progetto pronto a rilasciare, con due step e un campo su ciascuno.
     */
    private function projectReadyToRelease(): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $roles = Role::factory()->count(2)->create();

        foreach ($roles as $position => $role) {
            $step = StepDefinition::factory()->for($template)->create([
                'position' => $position + 1,
                'role_id' => $role->id,
            ]);

            FieldDefinition::factory()->for($step)->create();
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

    private function activeStep(): ReleaseStep
    {
        return $this->releaseInProgress()->steps->first();
    }

    /**
     * Release conclusa nella sua forma reale: due step chiusi e nessuno attivo.
     */
    private function completedRelease(): Release
    {
        $release = Release::factory()->completed()->create();

        foreach ([1, 2] as $position) {
            $step = ReleaseStep::factory()->for($release)->completed()->create([
                'position' => $position,
                'assigned_user_id' => User::factory()->create()->id,
            ]);

            ReleaseStepField::factory()->for($step)->create([
                'position' => 1,
                'type' => FieldType::ShortText,
                'is_required' => true,
                'value' => '2.4.0',
            ]);
        }

        return $release->load('steps.fields', 'steps.assignedUser');
    }

    /**
     * Release in corso con due step: il primo attivo, il secondo bloccato.
     */
    private function releaseInProgress(): Release
    {
        $release = Release::factory()->create();

        foreach ([1, 2] as $position) {
            $step = ReleaseStep::factory()->for($release)->create([
                'position' => $position,
                'status' => $position === 1 ? ReleaseStepStatus::Active : ReleaseStepStatus::Blocked,
                'assigned_user_id' => User::factory()->create()->id,
            ]);

            ReleaseStepField::factory()->for($step)->create([
                'position' => 1,
                'type' => FieldType::ShortText,
                'is_required' => true,
            ]);
        }

        return $release->load('steps.fields', 'steps.assignedUser');
    }
}
