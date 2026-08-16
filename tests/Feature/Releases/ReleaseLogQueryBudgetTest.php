<?php

namespace Tests\Feature\Releases;

use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Il registro carica per ogni voce l'attore e lo step interessato: la stessa forma
 * di N+1 gia vista sulle altre schermate operative, su una tabella che cresce per
 * tutta la vita del rilascio e non si potera mai.
 *
 * Il punto piu esposto e la relazione verso lo step, che e **nullable** — l'avvio
 * non ne ha. Una relazione nullable caricata pigramente e il modo piu silenzioso di
 * reintrodurre una query per riga, perche su un insieme di prova fatto di sole
 * chiusure non si vede.
 *
 * Come negli altri budget, il test non fissa un numero assoluto — lo cambierebbe
 * ogni ottimizzazione legittima — ma l'invariante: **stesso costo con due voci e
 * con dieci**. Due e non una: il perche e scritto sul primo caso.
 */
class ReleaseLogQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_register_costs_the_same_on_two_entries_and_on_ten(): void
    {
        $member = User::factory()->member()->create();

        /*
         * **Due e non una**, ed e il dettaglio che rende il confronto valido: con
         * una sola voce — che riguarda il rilascio e non uno step — l'eager loading
         * della relazione nullable non esegue alcuna query, perche non c'e nulla da
         * caricare. Il confronto misurerebbe la differenza di **forma** fra i due
         * insiemi invece del costo per riga, e fallirebbe su codice sano. Due voci
         * sono il minimo che contiene entrambe le forme.
         */
        $short = $this->releaseWithEntries(count: 2);
        $long = $this->releaseWithEntries(count: 10);

        $twoCost = $this->queriesWhile(fn () => $this->readTheRegister($member, $short));
        $tenCost = $this->queriesWhile(fn () => $this->readTheRegister($member, $long));

        $this->assertSame(
            $twoCost,
            $tenCost,
            "La lettura e costata {$twoCost} query su due voci e {$tenCost} su dieci: manca un eager loading su attore o step interessato."
        );
    }

    public function test_the_visibility_filter_does_not_cost_a_query_per_entry(): void
    {
        $admin = User::factory()->admin()->create();

        $short = $this->releaseWithEntries(count: 2, withAttempts: true);
        $long = $this->releaseWithEntries(count: 10, withAttempts: true);

        // Un amministratore vede anche i tentativi, cioe l'insieme piu grande: se il
        // filtro di visibilita fosse applicato riga per riga invece che in query, il
        // costo crescerebbe proprio sul lettore che ne ha di piu.
        $twoCost = $this->queriesWhile(fn () => $this->readTheRegister($admin, $short));
        $tenCost = $this->queriesWhile(fn () => $this->readTheRegister($admin, $long));

        $this->assertSame(
            $twoCost,
            $tenCost,
            "La lettura di un amministratore e costata {$twoCost} query su due voci e {$tenCost} su dieci: il filtro di visibilita non e in query."
        );
    }

    /**
     * Legge la pagina come la legge chi la apre, e non una query scritta qui: una
     * lettura costruita dal test resterebbe verde anche se domani la vista risalisse
     * a una relazione dentro il ciclo del Blade.
     */
    private function readTheRegister(User $user, Release $release): void
    {
        $this->actingAs($user)->get(route('releases.log', $release))->assertOk();
    }

    /**
     * Release con un registro popolato.
     *
     * **Le forme si alternano di proposito**: voci legate a uno step e voci che
     * riguardano il rilascio nel suo insieme, ognuna con un attore diverso. Un
     * insieme di sole chiusure non proverebbe che la relazione nullable verso lo
     * step non fa scattare una query per riga.
     */
    private function releaseWithEntries(int $count, bool $withAttempts = false): Release
    {
        $release = Release::factory()->for(Project::factory()->withTemplate())->create();

        $step = ReleaseStep::factory()->for($release)->create(['position' => 1]);

        for ($index = 0; $index < $count; $index++) {
            $actor = User::factory()->create();

            if ($index % 2 === 0) {
                // Riguarda il rilascio: nessuno step, il ramo che il caricamento
                // pigro percorrerebbe senza dare nell'occhio.
                ReleaseEvent::factory()->for($release)->create(['user_id' => $actor->id]);

                continue;
            }

            ReleaseEvent::factory()->stepCompleted($step)->create(['user_id' => $actor->id]);
        }

        if ($withAttempts) {
            ReleaseEvent::factory()->unauthorizedAttempt($step)->create([
                'user_id' => User::factory()->create()->id,
            ]);
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
