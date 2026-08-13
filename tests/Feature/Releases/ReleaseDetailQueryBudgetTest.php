<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseStepStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Il PRD indica l'N+1 come rischio strutturale delle catene annidate, e questa e la
 * vista che ne carica di piu: per ogni step la release, il responsabile, l'autore
 * della chiusura e tutti i campi forniti.
 *
 * Come nelle altre prove di budget del progetto, il test **non fissa un numero
 * assoluto** — lo cambierebbe ogni ottimizzazione legittima — ma l'invariante che
 * conta: lo stesso costo su una catena corta e su una lunga, e con pochi o molti
 * campi per step.
 */
class ReleaseDetailQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_the_detail_costs_the_same_on_three_and_on_twelve_steps(): void
    {
        $short = $this->releaseWith(steps: 3, fieldsPerStep: 1);
        $long = $this->releaseWith(steps: 12, fieldsPerStep: 1);

        $shortCost = $this->queriesWhile(fn () => $this->readDetail($short));
        $longCost = $this->queriesWhile(fn () => $this->readDetail($long));

        $this->assertSame(
            $shortCost,
            $longCost,
            "La lettura e costata {$shortCost} query su tre step e {$longCost} su dodici: manca un eager loading su campi, responsabile o autore della chiusura."
        );
    }

    public function test_reading_the_detail_costs_the_same_with_one_and_with_five_fields_per_step(): void
    {
        $narrow = $this->releaseWith(steps: 5, fieldsPerStep: 1);
        $wide = $this->releaseWith(steps: 5, fieldsPerStep: 5);

        $narrowCost = $this->queriesWhile(fn () => $this->readDetail($narrow));
        $wideCost = $this->queriesWhile(fn () => $this->readDetail($wide));

        $this->assertSame(
            $narrowCost,
            $wideCost,
            "La lettura e costata {$narrowCost} query con un campo per step e {$wideCost} con cinque: i campi vengono caricati step per step invece che in una sola volta."
        );
    }

    public function test_rendering_the_page_costs_the_same_on_three_and_on_twelve_steps(): void
    {
        /*
         * I due casi sopra misurano la lettura come il componente la scrive; questo
         * misura **la pagina resa**, che e cio che l'utente paga. Senza, un eager
         * loading tolto da `⚡show.blade.php` lascerebbe la suite verde: la replica
         * di questo test continuerebbe a caricare bene per conto proprio.
         */
        $short = $this->releaseWith(steps: 3, fieldsPerStep: 2);
        $long = $this->releaseWith(steps: 12, fieldsPerStep: 2);
        $reader = User::factory()->create();

        // Richiesta di riscaldamento: il primo accesso paga la sessione e le letture
        // di primo avvio, che non appartengono alla catena.
        $this->actingAs($reader)->get(route('releases.show', $short))->assertOk();

        $shortCost = $this->queriesWhile(
            fn () => $this->actingAs($reader)->get(route('releases.show', $short))->assertOk()
        );
        $longCost = $this->queriesWhile(
            fn () => $this->actingAs($reader)->get(route('releases.show', $long))->assertOk()
        );

        $this->assertSame(
            $shortCost,
            $longCost,
            "La pagina e costata {$shortCost} query su tre step e {$longCost} su dodici: manca un eager loading nel componente `releases.show`."
        );
    }

    public function test_a_freshly_started_release_is_read_once_and_never_again(): void
    {
        /*
         * Su una release appena avviata lo step attivo e il **primo** della catena:
         * non ha un precedente da cui leggere l'istante, quindi `activationInstant()`
         * ripiega su `release->started_at`. Senza la relazione inversa popolata a mano
         * nel componente, quel ripiego risalirebbe alla release con una query propria.
         *
         * E **una sola** query, che nessun confronto sulla lunghezza della catena
         * potrebbe vedere: per questo l'invariante qui non e il costo totale — che
         * resterebbe un numero assoluto — ma il fatto che la riga della release si
         * legga una volta sola, quella del binding di rotta.
         */
        $fresh = $this->releaseWith(steps: 4, fieldsPerStep: 2, activePosition: 1);
        $reader = User::factory()->create();

        // Richiesta di riscaldamento: il primo accesso paga letture di primo avvio.
        $this->actingAs($reader)->get(route('releases.show', $fresh))->assertOk();

        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->actingAs($reader)->get(route('releases.show', $fresh))->assertOk();

        $reads = array_values(array_filter(
            $statements,
            fn (string $sql): bool => preg_match('/\bfrom\s+["`\[]?releases["`\]]?/i', $sql) === 1
        ));

        $this->assertCount(
            1,
            $reads,
            'La riga della release deve essere letta una volta sola, dal binding di rotta: '.count($reads)
            .' letture significano che qualcosa risale alla release step per step — probabilmente la relazione inversa non e popolata.'
        );
    }

    public function test_the_activation_instants_do_not_cost_a_single_query(): void
    {
        /*
         * Le due forme si alternano di proposito. Su una release appena avviata lo
         * step attivo e il primo della catena: non ha un precedente da cui leggere
         * l'istante e `activationInstant()` ripiega su `release->started_at`. E il
         * ramo piu facile da lasciare scoperto — senza la relazione inversa popolata
         * a mano, quel ripiego caricherebbe la release con una query propria.
         */
        foreach ([$this->releaseWith(steps: 4, fieldsPerStep: 2, activePosition: 1),
            $this->releaseWith(steps: 4, fieldsPerStep: 2, activePosition: 3)] as $release) {
            $steps = $this->loadedChain($release);

            $queries = $this->queriesWhile(function () use ($steps): void {
                $steps->each(fn (ReleaseStep $step): string => $step->activationInstant()->toIso8601String());
            });

            $this->assertSame(
                0,
                $queries,
                "La lettura degli istanti di attivazione ha eseguito {$queries} query: manca l'alias della sottoquery o la relazione inversa verso la release."
            );
        }
    }

    /**
     * Legge quanto la schermata mostra della catena e del riquadro dei dati.
     *
     * Gli attributi vengono **raccolti** e non solo sfiorati: e la lettura a poter
     * far scattare un caricamento pigro, cioe la query che il test misura.
     */
    private function readDetail(Release $release): void
    {
        $fresh = Release::query()->whereKey($release->id)->firstOrFail();

        $fresh->load([
            'project',
            'workflowTemplate',
            'startedBy',
            'steps' => fn ($steps) => $steps
                ->withActivationInstant()
                ->with(['fields', 'assignedUser', 'completedBy']),
        ]);

        $fresh->steps->each(fn (ReleaseStep $step) => $step->setRelation('release', $fresh));

        $header = implode('/', [
            $fresh->label,
            $fresh->project->name,
            $fresh->workflowTemplate->name,
            $fresh->startedBy->name,
            $fresh->started_at->toIso8601String(),
        ]);

        $chain = $fresh->steps->map(fn (ReleaseStep $step): string => implode('/', [
            $step->name,
            $step->role_name,
            $step->status->value,
            $step->assignedUser->name,
            (string) $step->completedBy?->name,
            $step->activationInstant()->toIso8601String(),
            $step->fields->map(fn (ReleaseStepField $field): string => $field->label.'='.$field->value)->implode(','),
        ]))->implode(' ');

        // Il risultato non serve: serve che la lettura sia avvenuta davvero.
        $this->assertNotSame('', $header.$chain);
    }

    /**
     * La catena come la schermata la carica, per misurare la sola lettura degli
     * istanti.
     *
     * @return Collection<int, ReleaseStep>
     */
    private function loadedChain(Release $release)
    {
        $release->load([
            'steps' => fn ($steps) => $steps->withActivationInstant()->with(['fields', 'assignedUser', 'completedBy']),
        ]);

        $release->steps->each(fn (ReleaseStep $step) => $step->setRelation('release', $release));

        return $release->steps;
    }

    /**
     * Release in corso con una catena della lunghezza richiesta: gli step prima di
     * quello attivo sono chiusi con i loro valori, quelli dopo sono bloccati.
     *
     * **Lo step attivo sta in penultima posizione**, e non e un dettaglio: la pagina
     * legge i campi soltanto sugli step **chiusi** — e sull'attivo, per contarli —
     * mentre su quelli bloccati non mostra nulla. Con lo step attivo in posizione 2,
     * una catena di tre e una di dodici avrebbero **un solo** step chiuso ciascuna e
     * il costo resterebbe costante anche senza alcun eager loading: il test
     * passerebbe misurando nulla. Cosi invece cio che cresce con la catena e proprio
     * il numero di step di cui la pagina legge campi e autore della chiusura.
     * Resta comunque uno step bloccato in coda, cioe tutti e tre gli stati.
     */
    private function releaseWith(int $steps, int $fieldsPerStep, ?int $activePosition = null): Release
    {
        $activePosition ??= max(1, $steps - 1);

        $release = Release::factory()
            ->for(Project::factory()->withTemplate())
            ->create(['started_at' => now()->subDays(3)]);

        for ($position = 1; $position <= $steps; $position++) {
            $state = match (true) {
                $position < $activePosition => 'completed',
                $position === $activePosition => 'active',
                default => 'blocked',
            };

            $step = ReleaseStep::factory()->for($release)->{$state}()->create(['position' => $position]);

            if ($state === 'completed') {
                // Chiusure scaglionate: e da queste che gli step successivi ricavano
                // il proprio istante di attivazione.
                $step->forceFill([
                    'status' => ReleaseStepStatus::Completed,
                    'completed_by' => User::factory()->create()->id,
                    'completed_at' => now()->subHours($steps - $position + 1),
                ])->save();
            }

            $fields = ReleaseStepField::factory()->for($step)->count($fieldsPerStep);

            $state === 'completed' ? $fields->filled()->create() : $fields->create();
        }

        return $release;
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
