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

    /**
     * Stessa invariante con i responsabili indicati in avvio (US-013): la
     * risoluzione degli override e **una sola** `whereIn` sui valori distinti, non
     * una query per ruolo, quindi neanche qui il costo puo dipendere dalla
     * lunghezza della catena.
     */
    public function test_starting_with_overrides_costs_the_same_on_a_short_and_on_a_long_chain(): void
    {
        $short = $this->projectWith(steps: 2, fieldsPerStep: 1);
        $long = $this->projectWith(steps: 5, fieldsPerStep: 14);
        $actor = User::factory()->admin()->create();
        $stand = User::factory()->create();

        $shortCost = $this->queriesWhile(fn () => $this->startWithOverrides($short, $actor, $stand));
        $longCost = $this->queriesWhile(fn () => $this->startWithOverrides($long, $actor, $stand));

        $this->assertSame(
            $shortCost,
            $longCost,
            "L'avvio con override e costato {$shortCost} query su due step e {$longCost} su cinque: il costo dipende dalla lunghezza della catena."
        );
    }

    /**
     * E non puo dipendere nemmeno dal **numero di ruoli sovrascritti**: e la stessa
     * query, con piu identificativi dentro.
     */
    public function test_overriding_many_roles_costs_the_same_as_overriding_one(): void
    {
        $actor = User::factory()->admin()->create();
        $stand = User::factory()->create();

        [$few, $fewRoles] = $this->projectWithDistinctRoles(2);
        [$many, $manyRoles] = $this->projectWithDistinctRoles(8);

        $fewCost = $this->queriesWhile(
            fn () => app(StartRelease::class)->handle(
                $few->fresh(), 'v-few', $actor, [$fewRoles[0] => $stand->id]
            )
        );

        $manyCost = $this->queriesWhile(
            fn () => app(StartRelease::class)->handle(
                $many->fresh(),
                'v-many',
                $actor,
                array_fill_keys($manyRoles, $stand->id)
            )
        );

        $this->assertSame(
            $fewCost,
            $manyCost,
            "Un override e costato {$fewCost} query e otto {$manyCost}: la risoluzione interroga per ruolo."
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
     * Avvia sovrascrivendo l'unico ruolo del processo costruito da `projectWith()`.
     */
    private function startWithOverrides(Project $project, User $actor, User $stand): Release
    {
        $project = $project->fresh();

        $overrides = $project->workflowTemplate->stepDefinitions
            ->pluck('role_id')
            ->unique()
            ->mapWithKeys(fn (string $roleId): array => [$roleId => $stand->id])
            ->all();

        return app(StartRelease::class)->handle($project, 'v'.$project->slug, $actor, $overrides);
    }

    /**
     * Progetto con un ruolo distinto per step, tutti coperti dalla mappatura.
     *
     * @return array{Project, list<string>}
     */
    private function projectWithDistinctRoles(int $roles): array
    {
        $template = WorkflowTemplate::factory()->create();
        $roleIds = [];

        for ($position = 1; $position <= $roles; $position++) {
            $role = Role::factory()->create();
            $roleIds[] = $role->id;

            StepDefinition::factory()->for($template)->create([
                'position' => $position,
                'role_id' => $role->id,
            ]);
        }

        $project = Project::factory()->withTemplate($template)->create();

        foreach ($roleIds as $roleId) {
            ProjectRoleAssignment::factory()->create([
                'project_id' => $project->id,
                'role_id' => $roleId,
                'user_id' => User::factory()->create()->id,
            ]);
        }

        return [$project->fresh(), $roleIds];
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
