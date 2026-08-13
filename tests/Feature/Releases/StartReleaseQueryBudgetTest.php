<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\StartRelease;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Il PRD indica l'N+1 come rischio strutturale delle catene annidate: qui la
 * catena e template -> step -> campi, e un ciclo di scritture riga per riga
 * renderebbe il costo dell'avvio proporzionale alla lunghezza del processo.
 *
 * Il test non fissa un numero assoluto — cambierebbe a ogni ottimizzazione
 * legittima — ma l'invariante che conta: **lo stesso** numero di query per una
 * catena corta e per una lunga.
 */
class StartReleaseQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_release_costs_the_same_on_a_short_and_on_a_long_chain(): void
    {
        $short = $this->projectWith(steps: 2, fieldsPerStep: 1);
        $long = $this->projectWith(steps: 5, fieldsPerStep: 14);
        $actor = User::factory()->admin()->create();

        $shortCost = $this->queriesWhile(fn () => $this->start($short, $actor));
        $longCost = $this->queriesWhile(fn () => $this->start($long, $actor));

        $this->assertSame(
            $shortCost,
            $longCost,
            "L'avvio e costato {$shortCost} query su due step e {$longCost} su cinque: il costo dipende dalla lunghezza della catena."
        );
    }

    public function test_reading_a_frozen_chain_costs_the_same_on_a_short_and_on_a_long_chain(): void
    {
        $actor = User::factory()->admin()->create();
        $shortRelease = $this->start($this->projectWith(steps: 2, fieldsPerStep: 1), $actor);
        $longRelease = $this->start($this->projectWith(steps: 5, fieldsPerStep: 14), $actor);

        $shortCost = $this->queriesWhile(fn () => $this->readChain($shortRelease));
        $longCost = $this->queriesWhile(fn () => $this->readChain($longRelease));

        $this->assertSame(
            $shortCost,
            $longCost,
            "La lettura e costata {$shortCost} query su due step e {$longCost} su cinque: manca un eager loading."
        );
    }

    /**
     * Legge la catena congelata come farebbe una schermata: step, campi e
     * responsabile di ciascuno.
     */
    private function readChain(Release $release): void
    {
        // Gli attributi vengono raccolti e non solo sfiorati: e la lettura a poter
        // far scattare un caricamento pigro, cioe la query che il test misura.
        $release->fresh()
            ->steps()
            ->with(['fields', 'assignedUser'])
            ->get()
            ->map(fn ($step): string => implode(' / ', [
                $step->name,
                $step->role_name,
                $step->assignedUser->name,
                $step->fields->pluck('label')->implode(', '),
            ]))
            ->all();
    }

    private function start(Project $project, User $actor): Release
    {
        return app(StartRelease::class)->handle($project->fresh(), 'v'.$project->slug, $actor);
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

    private function projectWith(int $steps, int $fieldsPerStep): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $role = Role::factory()->create();

        for ($position = 1; $position <= $steps; $position++) {
            $step = StepDefinition::factory()->for($template)->create([
                'position' => $position,
                'role_id' => $role->id,
            ]);

            FieldDefinition::factory()->count($fieldsPerStep)->for($step)->create();
        }

        $project = Project::factory()->withTemplate($template)->create();

        ProjectRoleAssignment::factory()->create([
            'project_id' => $project->id,
            'role_id' => $role->id,
            'user_id' => User::factory()->create()->id,
        ]);

        return $project->fresh();
    }
}
