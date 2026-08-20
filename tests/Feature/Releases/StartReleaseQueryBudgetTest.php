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

    public function test_starting_with_overrides_costs_the_same_on_a_short_and_on_a_long_chain(): void
    {
        $short = $this->projectWith(steps: 2, fieldsPerStep: 1, roles: 2);
        $long = $this->projectWith(steps: 5, fieldsPerStep: 14, roles: 2);
        $actor = User::factory()->admin()->create();

        // Le persone sostitutive nascono **fuori** dalla misura: crearle dentro
        // conterebbe le loro insert come costo dell'avvio.
        $shortOverrides = $this->overridesFor($short);
        $longOverrides = $this->overridesFor($long);

        $shortCost = $this->queriesWhile(fn () => $this->start($short, $actor, $shortOverrides));
        $longCost = $this->queriesWhile(fn () => $this->start($long, $actor, $longOverrides));

        $this->assertSame(
            $shortCost,
            $longCost,
            "L'avvio con override e costato {$shortCost} query su due step e {$longCost} su cinque: il costo dipende dalla lunghezza della catena."
        );
    }

    public function test_overriding_one_role_costs_the_same_as_overriding_five(): void
    {
        /*
         * E la prova che la risoluzione delle persone indicate e **batch** e non per
         * ruolo: due progetti della stessa forma, cinque ruoli distinti ciascuno, e
         * il secondo con tutti e cinque sostituiti da cinque persone diverse. Se
         * l'implementazione tornasse a una query per ruolo, il secondo costerebbe
         * quattro query in piu.
         */
        $one = $this->projectWith(steps: 5, fieldsPerStep: 2, roles: 5);
        $five = $this->projectWith(steps: 5, fieldsPerStep: 2, roles: 5);
        $actor = User::factory()->admin()->create();

        $oneOverride = $this->overridesFor($one, roles: 1);
        $fiveOverrides = $this->overridesFor($five, roles: 5);

        $this->assertCount(1, $oneOverride);
        $this->assertCount(5, $fiveOverrides);

        $oneCost = $this->queriesWhile(fn () => $this->start($one, $actor, $oneOverride));
        $fiveCost = $this->queriesWhile(fn () => $this->start($five, $actor, $fiveOverrides));

        $this->assertSame(
            $oneCost,
            $fiveCost,
            "L'avvio e costato {$oneCost} query con un ruolo sostituito e {$fiveCost} con cinque: la risoluzione avviene per ruolo invece che in blocco."
        );
    }

    public function test_an_override_costs_one_query_more_and_the_gap_does_not_grow_with_the_roles(): void
    {
        $actor = User::factory()->admin()->create();

        $gap = function (int $roles) use ($actor): int {
            $plain = $this->projectWith(steps: 5, fieldsPerStep: 2, roles: $roles);
            $overridden = $this->projectWith(steps: 5, fieldsPerStep: 2, roles: $roles);

            $overrides = $this->overridesFor($overridden, roles: $roles);

            $plainCost = $this->queriesWhile(fn () => $this->start($plain, $actor));
            $overriddenCost = $this->queriesWhile(fn () => $this->start($overridden, $actor, $overrides));

            return $overriddenCost - $plainCost;
        };

        $small = $gap(1);
        $large = $gap(5);

        $this->assertSame(
            1,
            $small,
            "L'override e costato {$small} query in piu della risoluzione ordinaria: attesa una sola lettura in blocco."
        );

        $this->assertSame(
            $small,
            $large,
            "Il sovraccosto dell'override e stato {$small} query con un ruolo e {$large} con cinque: cresce col numero di ruoli."
        );
    }

    /**
     * Una persona sostitutiva per i primi `$roles` ruoli del processo, indicizzata
     * per ruolo.
     *
     * @return array<string, string>
     */
    private function overridesFor(Project $project, ?int $roles = null): array
    {
        $roleIds = $project->workflowTemplate->stepDefinitions->pluck('role_id')->unique()->values();

        return $roleIds
            ->take($roles ?? $roleIds->count())
            ->mapWithKeys(fn (string $roleId): array => [
                $roleId => User::factory()->create()->id,
            ])
            ->all();
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

    /**
     * @param  array<string, string>  $overrides
     */
    private function start(Project $project, User $actor, array $overrides = []): Release
    {
        return app(StartRelease::class)->handle($project->fresh(), 'v'.$project->slug, $actor, $overrides);
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

    /**
     * Progetto pronto a rilasciare con `$steps` step e `$roles` ruoli distinti, i
     * ruoli ripetuti a giro quando gli step sono piu dei ruoli.
     *
     * Il parametro `$roles` esiste perche il costo dell'override va misurato al
     * variare del **numero di ruoli** e non solo della lunghezza della catena: un
     * secondo helper accanto a questo avrebbe fatto divergere le due forme di
     * scenario.
     */
    private function projectWith(int $steps, int $fieldsPerStep, int $roles = 1): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $created = Role::factory()->count($roles)->create();

        for ($position = 1; $position <= $steps; $position++) {
            $step = StepDefinition::factory()->for($template)->create([
                'position' => $position,
                'role_id' => $created[($position - 1) % $roles]->id,
            ]);

            FieldDefinition::factory()->count($fieldsPerStep)->for($step)->create();
        }

        $project = Project::factory()->withTemplate($template)->create();

        foreach ($created as $role) {
            ProjectRoleAssignment::factory()->create([
                'project_id' => $project->id,
                'role_id' => $role->id,
                'user_id' => User::factory()->create()->id,
            ]);
        }

        return $project->fresh();
    }
}
