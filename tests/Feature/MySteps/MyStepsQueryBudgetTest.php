<?php

namespace Tests\Feature\MySteps;

use App\Enums\ReleaseStepStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Il PRD indica l'N+1 come rischio strutturale delle catene annidate e nomina "i
 * miei step" fra i candidati naturali: e la schermata che carica, per ogni step,
 * la release, il progetto, la lunghezza della catena e — nel secondo blocco — chi
 * trattiene il flusso.
 *
 * Come in `CloseStepQueryBudgetTest` e `StartReleaseQueryBudgetTest`, il test non
 * fissa un numero assoluto — lo cambierebbe ogni ottimizzazione legittima — ma
 * l'invariante che conta: **lo stesso** costo con uno e con dieci.
 */
class MyStepsQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_the_awaiting_steps_costs_the_same_on_one_and_on_ten(): void
    {
        $alone = User::factory()->create();
        $busy = User::factory()->create();

        $this->awaitingSteps($alone, count: 1);
        $this->awaitingSteps($busy, count: 10);

        $oneCost = $this->queriesWhile(fn () => $this->readAwaitingSteps($alone));
        $tenCost = $this->queriesWhile(fn () => $this->readAwaitingSteps($busy));

        $this->assertSame(
            $oneCost,
            $tenCost,
            "La lettura e costata {$oneCost} query su uno step e {$tenCost} su dieci: manca un eager loading su release, progetto o conteggio della catena."
        );
    }

    public function test_reading_the_waiting_releases_costs_the_same_on_one_and_on_ten(): void
    {
        $alone = User::factory()->create();
        $involved = User::factory()->create();

        $this->waitingReleases($alone, count: 1);
        $this->waitingReleases($involved, count: 10);

        $oneCost = $this->queriesWhile(fn () => $this->readWaitingReleases($alone));
        $tenCost = $this->queriesWhile(fn () => $this->readWaitingReleases($involved));

        $this->assertSame(
            $oneCost,
            $tenCost,
            "La lettura e costata {$oneCost} query su una release e {$tenCost} su dieci: manca un eager loading su progetto, step attivo o responsabile."
        );
    }

    public function test_the_activation_instant_does_not_cost_a_query_per_step(): void
    {
        $member = User::factory()->create();
        $this->awaitingSteps($member, count: 10);

        $steps = ReleaseStep::query()
            ->awaitingUser($member)
            ->withActivationInstant()
            ->with(['release' => fn ($release) => $release->with('project')->withCount('steps')])
            ->get();

        $queries = $this->queriesWhile(function () use ($steps): void {
            $steps->each(fn (ReleaseStep $step): string => $step->activationInstant()->toIso8601String());
        });

        // L'istante arriva dalla sottoquery correlata gia risolta nella prima
        // lettura: leggerlo qui non deve toccare il database, altrimenti il costo
        // ricomparirebbe a ogni aggiornamento Livewire della pagina.
        $this->assertSame(0, $queries, "La lettura degli istanti ha eseguito {$queries} query.");
    }

    /**
     * Legge quanto la schermata mostra del primo blocco.
     *
     * Gli attributi vengono **raccolti** e non solo sfiorati: e la lettura a poter
     * far scattare un caricamento pigro, cioe la query che il test misura.
     */
    private function readAwaitingSteps(User $user): void
    {
        $steps = ReleaseStep::query()
            ->awaitingUser($user)
            ->withActivationInstant()
            ->with(['release' => fn ($release) => $release->with('project')->withCount('steps')])
            ->get();

        $steps->map(fn (ReleaseStep $step): string => implode('/', [
            $step->name,
            $step->role_name,
            (string) $step->position,
            (string) $step->release->steps_count,
            $step->release->label,
            $step->release->project->name,
            $step->activationInstant()->toIso8601String(),
        ]))->implode(' ');
    }

    /**
     * Legge quanto la schermata mostra del blocco delle release in attesa.
     */
    private function readWaitingReleases(User $user): void
    {
        $releases = Release::query()
            ->inProgress()
            ->involving($user)
            ->whereDoesntHave('steps', fn ($steps) => $steps
                ->where('assigned_user_id', $user->id)
                ->where('status', ReleaseStepStatus::Active))
            ->with([
                'project',
                'activeStep' => fn ($activeStep) => $activeStep->withActivationInstant()->with('assignedUser'),
            ])
            ->get();

        $releases->map(fn (Release $release): string => implode('/', [
            $release->label,
            $release->project->name,
            (string) $release->activeStep?->name,
            (string) $release->activeStep?->assignedUser->name,
            (string) $release->activeStep?->activationInstant()->toIso8601String(),
        ]))->implode(' ');
    }

    /**
     * Step attivi in carico alla persona, ognuno su un progetto e una release
     * diversi: e la forma reale del primo blocco, non dieci righe della stessa
     * catena.
     */
    private function awaitingSteps(User $user, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $release = $this->chain(steps: 3);

            $release->steps->first()->forceFill([
                'status' => ReleaseStepStatus::Completed,
                'completed_at' => now()->subHours($index + 1),
            ])->save();

            $release->steps->firstWhere('position', 2)->update([
                'assigned_user_id' => $user->id,
                'status' => ReleaseStepStatus::Active,
            ]);
        }
    }

    /**
     * Release in corso che coinvolgono la persona ma sono ferme su qualcun altro.
     */
    private function waitingReleases(User $user, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $release = $this->chain(steps: 3);

            $release->steps->first()->forceFill([
                'status' => ReleaseStepStatus::Completed,
                'completed_by' => $user->id,
                'completed_at' => now()->subHours($index + 1),
            ])->save();

            $release->steps->first()->update(['assigned_user_id' => $user->id]);

            // Il turno e di un'altra persona, ognuna diversa: e il responsabile che
            // la schermata nomina, e caricarlo riga per riga sarebbe l'N+1 cercato.
            $release->steps->firstWhere('position', 2)->update([
                'assigned_user_id' => User::factory()->create()->id,
                'status' => ReleaseStepStatus::Active,
            ]);
        }
    }

    /**
     * Release in corso su un progetto proprio, con una catena di step bloccati.
     */
    private function chain(int $steps): Release
    {
        $release = Release::factory()
            ->for(Project::factory()->withTemplate())
            ->create();

        for ($position = 1; $position <= $steps; $position++) {
            ReleaseStep::factory()->for($release)->blocked()->create(['position' => $position]);
        }

        return $release->load('steps');
    }

    /**
     * Numero di query eseguite durante la chiamata.
     */
    private function queriesWhile(callable $work): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $work();

        // `DB::listen` non si disiscrive: il contatore successivo usa la propria
        // variabile, quindi i listener accumulati non si sommano fra loro.
        return $count;
    }
}
