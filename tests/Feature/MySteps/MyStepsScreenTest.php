<?php

namespace Tests\Feature\MySteps;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonInterface;
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

        // Uno step **visibile** nello stesso insieme di prova, e non solo quello da
        // escludere: senza, l'asserzione passerebbe anche se la schermata non
        // rendesse nulla, cioe se la query fosse rotta invece che filtrata bene.
        $mine = $this->assign(
            $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3),
            position: 1, user: $member, role: 'QA'
        );

        $release = $this->releaseOn('Sito Corporate', 'v3.1.0', steps: 3);
        $foreign = $this->assign($release, position: 1, user: $colleague, role: 'DevOps');

        $this->actingAs($member)->get(route('home'))
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($foreign->name)
            ->assertDontSee('Sito Corporate');
    }

    public function test_not_even_an_administrator_sees_the_steps_of_others_here(): void
    {
        // La Policy gliene concede la lettura: e proprio per questo che il filtro
        // di questa schermata e sull'assegnazione e non sull'autorizzazione.
        $administrator = User::factory()->admin()->create();
        $colleague = User::factory()->create();

        $mine = $this->assign(
            $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3),
            position: 1, user: $administrator, role: 'Dev Lead'
        );

        $release = $this->releaseOn('Sito Corporate', 'v3.1.0', steps: 3);
        $foreign = $this->assign($release, position: 1, user: $colleague, role: 'DevOps');

        $response = $this->actingAs($administrator)->get(route('home'))->assertOk();

        // Vede il proprio step, quindi la schermata funziona; non vede quello
        // altrui, che e il criterio.
        $response->assertSee($mine->name);
        $response->assertDontSee($foreign->name);
        $response->assertDontSee('Sito Corporate');
    }

    public function test_the_oldest_open_step_comes_first(): void
    {
        // E l'unica priorita utile a chi entra: chi aspetta da piu tempo sta in
        // cima. Senza questa asserzione togliere l'ordinamento lascerebbe la suite
        // verde, perche `assertSee` non guarda la posizione.
        $member = User::factory()->create();

        $recent = $this->releaseOn('App Preventivi', 'v1.9.2', steps: 3);
        $this->closeFirstStepOf($recent, at: now()->subMinutes(20));
        $recentStep = $this->assign($recent, position: 2, user: $member, role: 'QA');

        $oldest = $this->releaseOn('Portale Clienti', 'v2.4.0', steps: 3);
        $this->closeFirstStepOf($oldest, at: now()->subDays(3));
        $oldestStep = $this->assign($oldest, position: 2, user: $member, role: 'Dev Lead');

        $body = (string) $this->actingAs($member)->get(route('home'))->assertOk()->getContent();

        // `e()` sui nomi: un passaggio come "Preparazione dell'ambiente" nella
        // pagina e gia escaped, e cercarne la forma grezza non lo troverebbe.
        $this->assertLessThan(
            strpos($body, e($recentStep->name)),
            strpos($body, e($oldestStep->name)),
            'Lo step aperto da piu tempo deve comparire per primo.'
        );
    }

    public function test_the_release_stuck_the_longest_comes_first_among_those_waiting(): void
    {
        $member = User::factory()->create();
        $slow = User::factory()->create(['name' => 'Davide Rossi']);
        $quick = User::factory()->create(['name' => 'Luca Serra']);

        $recent = $this->releaseOn('App Preventivi', 'v1.9.2', steps: 3);
        $this->involve($recent, user: $member, at: now()->subHours(6));
        $this->assign($recent, position: 2, user: $quick, role: 'DevOps');

        $oldest = $this->releaseOn('Sito Corporate', 'v3.1.0', steps: 3);
        $this->involve($oldest, user: $member, at: now()->subDays(2));
        $this->assign($oldest, position: 2, user: $slow, role: 'DevOps');

        $body = (string) $this->actingAs($member)->get(route('home'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'Luca Serra'),
            strpos($body, 'Davide Rossi'),
            'La release ferma da piu tempo deve comparire per prima.'
        );
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
     * Chiude il primo step della catena a un istante dato: e da li che
     * `activationInstant()` ricava da quanto il successivo e aperto.
     */
    private function closeFirstStepOf(Release $release, CarbonInterface $at): ReleaseStep
    {
        $step = $release->steps->first();

        $step->forceFill([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $step->assigned_user_id,
            'completed_at' => $at,
        ])->save();

        return $step;
    }

    /**
     * Coinvolge una persona nella release chiudendo per lei il primo step: e cosi
     * che si resta coinvolti in un rilascio su cui non si ha piu il turno.
     */
    private function involve(Release $release, User $user, CarbonInterface $at): ReleaseStep
    {
        $step = $this->assign($release, position: 1, user: $user, role: 'Dev Lead');

        $step->forceFill([
            'status' => ReleaseStepStatus::Completed,
            'completed_by' => $user->id,
            'completed_at' => $at,
        ])->save();

        return $step;
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
