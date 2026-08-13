<?php

namespace Tests\Feature\MySteps;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La schermata di ingresso: cosa compare, cosa no, e cosa viene annunciato.
 *
 * Il criterio piu facile da sbagliare — e quindi il primo a essere coperto — e
 * che **anche un amministratore** non vede qui gli step altrui: `ReleaseStepPolicy`
 * gliene concede la lettura, ma questa schermata si chiama "i miei step" e
 * mostrarglieli la trasformerebbe in un cruscotto di sorveglianza.
 *
 * Copre anche il contratto di accessibilita fissato dal mockup — contatore in
 * `aria-live`, stato reso con icona **e** parola, un solo `h1` — perche e un
 * criterio di accettazione e non una rifinitura.
 */
class MyStepsScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_open_steps_of_the_person_across_every_project(): void
    {
        $member = User::factory()->create();

        $first = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 5);
        $this->assign($first, position: 2, user: $member, role: 'QA');

        $second = $this->releaseOn('App Preventivi', 'v1.9.2', steps: 3);
        $this->assign($second, position: 1, user: $member, role: 'Product Manager');

        $response = $this->actingAs($member)->get(route('home'))->assertOk();

        $response->assertSee('Portale Clienti');
        $response->assertSee('v2.4.0');
        $response->assertSee($first->steps->get(1)->name);
        $response->assertSee(__('my-steps.step_position', ['position' => 2, 'total' => 5]));
        $response->assertSee(__('my-steps.step_as_role', ['role' => 'QA']));

        $response->assertSee('App Preventivi');
        $response->assertSee('v1.9.2');
        $response->assertSee(__('my-steps.step_position', ['position' => 1, 'total' => 3]));
        $response->assertSee(__('my-steps.step_as_role', ['role' => 'Product Manager']));

        // Due step su due progetti: il contatore dice entrambi i numeri.
        $response->assertSee(trans_choice('my-steps.counter', 2, [
            'count' => 2,
            'projects' => trans_choice('my-steps.counter_projects', 2, ['count' => 2]),
        ]));
    }

    public function test_a_step_assigned_to_someone_else_never_appears(): void
    {
        $member = User::factory()->create();
        $colleague = User::factory()->create();

        $release = $this->releaseOn('Sito Corporate', 'v3.1.0', steps: 3);
        $foreign = $this->assign($release, position: 1, user: $colleague, role: 'DevOps');

        $this->actingAs($member)->get(route('home'))
            ->assertOk()
            ->assertDontSee($foreign->name);
    }

    public function test_not_even_an_administrator_sees_the_steps_of_others_here(): void
    {
        // La Policy gliene concede la lettura: e proprio per questo che il filtro
        // di questa schermata e sull'assegnazione e non sull'autorizzazione.
        $administrator = User::factory()->admin()->create();
        $colleague = User::factory()->create();

        $release = $this->releaseOn('Sito Corporate', 'v3.1.0', steps: 3);
        $foreign = $this->assign($release, position: 1, user: $colleague, role: 'DevOps');

        $response = $this->actingAs($administrator)->get(route('home'))->assertOk();

        $response->assertDontSee($foreign->name);
        $response->assertSee(__('my-steps.empty_heading'));
    }

    public function test_blocked_completed_and_concluded_steps_stay_out_of_the_list(): void
    {
        $member = User::factory()->create();

        $running = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3);
        $blocked = $running->steps->get(1);
        $blocked->update(['assigned_user_id' => $member->id]);
        $completed = $this->assign($running, position: 1, user: $member, role: 'QA');
        // `forceFill` e non `update`: `completed_at` e `completed_by` non sono
        // mass-assignable di proposito — le scrive solo `CloseStep` — e un
        // `update()` le lascerebbe cadere in silenzio.
        $completed->forceFill(['status' => ReleaseStepStatus::Completed, 'completed_at' => now()])->save();

        $concluded = $this->releaseOn('App Preventivi', 'v1.9.2', steps: 2);
        $concluded->forceFill(['status' => ReleaseStatus::Completed, 'completed_at' => now()])->save();
        $onClosedRelease = $this->assign($concluded, position: 1, user: $member, role: 'QA');

        $response = $this->actingAs($member)->get(route('home'))->assertOk();

        $response->assertDontSee($blocked->name);
        $response->assertDontSee($completed->name);
        $response->assertDontSee($onClosedRelease->name);
        $response->assertSee(__('my-steps.empty_heading'));
    }

    public function test_the_empty_state_says_when_a_step_will_appear(): void
    {
        $member = User::factory()->create();

        $response = $this->actingAs($member)->get(route('home'))->assertOk();

        $response->assertSee(__('my-steps.empty_heading'));
        // Non basta dire che non c'e niente: chi entra deve sapere se sia normale.
        $response->assertSee(__('my-steps.empty_explained'));
        $response->assertSee(trans_choice('my-steps.counter', 0, [
            'count' => 0,
            'projects' => trans_choice('my-steps.counter_projects', 0, ['count' => 0]),
        ]));
    }

    public function test_the_waiting_block_names_who_holds_the_flow_and_for_how_long(): void
    {
        $member = User::factory()->create();
        $holder = User::factory()->create(['name' => 'Davide Rossi']);

        $release = $this->releaseOn('Sito Corporate', 'v3.1.0', steps: 3);
        // Il primo step e gia chiuso e il flusso e passato: e cosi che una persona
        // resta coinvolta in una release su cui non ha piu il turno.
        $mine = $this->assign($release, position: 1, user: $member, role: 'Dev Lead');
        $mine->forceFill([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $member->id,
            'completed_at' => now()->subDays(2),
        ])->save();
        $held = $this->assign($release, position: 2, user: $holder, role: 'DevOps');

        $response = $this->actingAs($member)->get(route('home'))->assertOk();

        $response->assertSee(__('my-steps.waiting_section'));
        $response->assertSee(__('my-steps.waiting_row', [
            'name' => 'Davide Rossi',
            'step' => $held->name,
            'duration' => '2 giorni',
        ]));
    }

    public function test_a_release_whose_turn_is_mine_is_listed_once_and_not_among_those_waiting(): void
    {
        $member = User::factory()->create();

        $release = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3);
        $mine = $this->assign($release, position: 1, user: $member, role: 'QA');

        $response = $this->actingAs($member)->get(route('home'))->assertOk();

        $response->assertSee($mine->name);
        // Se il turno e tuo, la release non e "in attesa di qualcun altro":
        // ripeterla sotto direbbe il contrario di cio che la pagina ha appena detto.
        $response->assertDontSee(__('my-steps.waiting_section'));
    }

    public function test_the_primary_command_opens_the_step(): void
    {
        $member = User::factory()->create();

        $release = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3);
        $mine = $this->assign($release, position: 1, user: $member, role: 'QA');

        $this->actingAs($member)->get(route('home'))
            ->assertOk()
            ->assertSee(route('releases.step', $mine))
            ->assertSee(__('my-steps.step_open_action'));
    }

    public function test_the_role_shown_is_the_frozen_one_and_not_the_current_name(): void
    {
        $member = User::factory()->create();

        $release = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3);
        $mine = $this->assign($release, position: 1, user: $member, role: 'QA');

        // Rinominare il ruolo dopo l'avvio non deve riscrivere cio che un rilascio
        // gia in corso dichiara: lo storico e la prova che il processo e stato
        // rispettato, e una prova che cambia retroattivamente non e una prova.
        Role::query()->whereKey($mine->role_id)->update(['name' => 'Quality Assurance']);

        $this->actingAs($member)->get(route('home'))
            ->assertOk()
            ->assertSee(__('my-steps.step_as_role', ['role' => 'QA']))
            ->assertDontSee('Quality Assurance');
    }

    public function test_the_open_duration_is_the_closure_of_the_previous_step(): void
    {
        // E la sola leva contro il rischio accettato n.1 del PRD: se "da quanto"
        // fosse impreciso, il blocco delle release in attesa perderebbe la sua
        // ragione d'essere.
        $member = User::factory()->create();

        $release = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3);
        $release->update(['started_at' => now()->subDays(9)]);

        $first = $release->steps->first();
        $first->forceFill([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $first->assigned_user_id,
            'completed_at' => now()->subHours(4),
        ])->save();

        $this->assign($release, position: 2, user: $member, role: 'QA');

        $this->actingAs($member)->get(route('home'))
            ->assertOk()
            // Quattro ore, non nove giorni: l'istante di avvio della release vale
            // solo per il primo step della catena.
            ->assertSee(__('my-steps.step_open_since', ['duration' => '4 ore']));
    }

    public function test_the_counter_is_announced_and_the_states_are_never_colour_only(): void
    {
        $member = User::factory()->create();

        $release = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3);
        $this->assign($release, position: 1, user: $member, role: 'QA');

        $response = $this->actingAs($member)->get(route('home'))->assertOk();

        // Il contatore cambia dopo un aggiornamento Livewire senza ricaricare la
        // pagina: senza regione live, chi non vede lo schermo non lo sa.
        $response->assertSee('aria-live="polite"', escape: false);

        // Lo stato e reso con icona **e** parola: chi non distingue i colori non
        // deve dedurre nulla dalla tinta.
        $response->assertSee(__('my-steps.status_your_turn'));
        $response->assertSee('data-flux-badge', escape: false);

        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), '<h1'),
            'La pagina deve avere un solo h1: le due sezioni sono h2.'
        );
    }

    /**
     * Release in corso su un progetto nominato, con una catena di step bloccati.
     */
    private function releaseOn(string $project, string $label, int $steps): Release
    {
        $release = Release::factory()
            ->for(Project::factory()->withTemplate()->create(['name' => $project]))
            ->create(['label' => $label]);

        for ($position = 1; $position <= $steps; $position++) {
            ReleaseStep::factory()->for($release)->blocked()->create(['position' => $position]);
        }

        return $release->load('steps');
    }

    /**
     * Rende attivo lo step nella posizione indicata e lo assegna a una persona,
     * con un ruolo congelato leggibile.
     */
    private function assign(Release $release, int $position, User $user, string $role): ReleaseStep
    {
        $step = $release->steps->firstWhere('position', $position);

        $step->update([
            'assigned_user_id' => $user->id,
            'role_name' => $role,
            'status' => ReleaseStepStatus::Active,
        ]);

        return $step->refresh();
    }
}
