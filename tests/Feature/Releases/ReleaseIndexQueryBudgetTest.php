<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L'elenco carica per ogni riga il progetto, lo step corrente, il suo responsabile,
 * la lunghezza della catena e — nello storico — chi ha consegnato: e la forma esatta
 * in cui l'N+1 rientra da una porta che sembra innocua.
 *
 * Come in `ReleaseDetailQueryBudgetTest` e `MyStepsQueryBudgetTest`, il test non
 * fissa un numero assoluto — lo cambierebbe ogni ottimizzazione legittima — ma
 * l'invariante che conta: **lo stesso** costo con una release e con dieci.
 *
 * Qui l'invariante e piu esposta che altrove: lo storico non e paginato per criterio
 * di accettazione, quindi un caricamento pigro non si limiterebbe a rallentare la
 * pagina — crescerebbe con lo storico, cioe per sempre.
 */
class ReleaseIndexQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_open_section_costs_the_same_on_one_and_on_ten(): void
    {
        $member = User::factory()->member()->create();

        $this->releasesInProgress(count: 1);
        $oneCost = $this->queriesWhile(fn () => $this->readTheList($member));

        $this->releasesInProgress(count: 9);
        $tenCost = $this->queriesWhile(fn () => $this->readTheList($member));

        $this->assertSame(
            $oneCost,
            $tenCost,
            "La lettura e costata {$oneCost} query su una release e {$tenCost} su dieci: manca un eager loading su progetto, step attivo, responsabile o lunghezza della catena."
        );
    }

    public function test_the_history_costs_the_same_on_one_and_on_ten(): void
    {
        $member = User::factory()->member()->create();

        $this->releasesCompleted(count: 1);
        $oneCost = $this->queriesWhile(fn () => $this->readTheList($member));

        $this->releasesCompleted(count: 9);
        $tenCost = $this->queriesWhile(fn () => $this->readTheList($member));

        $this->assertSame(
            $oneCost,
            $tenCost,
            "La lettura dello storico e costata {$oneCost} query su una release e {$tenCost} su dieci: manca un eager loading su progetto o su chi ha consegnato."
        );
    }

    public function test_filtering_by_status_does_not_read_the_hidden_section(): void
    {
        $member = User::factory()->member()->create();

        $this->releasesInProgress(count: 3);
        $this->releasesCompleted(count: 3);

        $both = $this->queriesWhile(fn () => $this->readTheList($member));
        $onlyOpen = $this->queriesWhile(
            fn () => $this->readTheList($member, ['stato' => ReleaseStatus::InProgress->value])
        );

        // Nascondere una sezione deve **risparmiare** la sua lettura, non solo
        // ometterla dalla pagina: un filtro che interroga comunque cio che non
        // mostra costa quanto non filtrare.
        $this->assertLessThan(
            $both,
            $onlyOpen,
            "Filtrare per stato ha eseguito {$onlyOpen} query contro le {$both} senza filtro: la sezione nascosta viene letta comunque."
        );
    }

    /**
     * Legge la pagina come la legge chi la apre.
     *
     * Sulla **pagina resa** e non su una query scritta qui dentro: una lettura
     * costruita dal test resterebbe verde anche se domani la vista risalisse a una
     * relazione riga per riga dentro il ciclo del Blade.
     *
     * @param  array<string, string>  $filters
     */
    private function readTheList(User $user, array $filters = []): void
    {
        $this->actingAs($user)->get(route('releases.index', $filters))->assertOk();
    }

    /**
     * Release in corso, ognuna su un progetto proprio.
     *
     * **Le forme si alternano di proposito.** Su una release appena avviata lo step
     * attivo e il primo della catena, quindi non ha un precedente da cui leggere
     * l'istante e `activationInstant()` ripiega su `release->started_at`: e il ramo
     * piu facile da lasciare scoperto, e un insieme che chiude sempre uno step
     * precedente misurerebbe soltanto il percorso gia sicuro.
     */
    private function releasesInProgress(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $release = $this->chain(steps: 3);

            if ($index % 2 === 0) {
                $release->steps->first()->update([
                    'assigned_user_id' => User::factory()->create()->id,
                    'status' => ReleaseStepStatus::Active,
                ]);

                continue;
            }

            /*
             * `forceFill` e non `update`: `completed_at` non e assegnabile in massa
             * — la scrive solo `CloseStep` — e cadrebbe in silenzio, riportando
             * l'istante di attivazione al ripiego su `started_at` e cancellando
             * proprio la differenza fra le due forme.
             */
            $release->steps->first()->forceFill([
                'status' => ReleaseStepStatus::Completed,
                'completed_at' => now()->subHours($index + 1),
            ])->save();

            $release->steps->firstWhere('position', 2)->update([
                'assigned_user_id' => User::factory()->create()->id,
                'status' => ReleaseStepStatus::Active,
            ]);
        }
    }

    /**
     * Release concluse, ognuna consegnata da una persona diversa: e chi lo storico
     * nomina, e caricarlo riga per riga sarebbe l'N+1 cercato.
     */
    private function releasesCompleted(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $release = $this->chain(steps: 2);

            $release->steps->each(fn (ReleaseStep $step) => $step->forceFill([
                'status' => ReleaseStepStatus::Completed,
                'completed_at' => now()->subHours($index + 1),
            ])->save());

            $release->forceFill([
                'status' => ReleaseStatus::Completed,
                'completed_by' => User::factory()->create()->id,
                'completed_at' => now()->subHours($index),
            ])->save();
        }
    }

    /**
     * Release su un progetto proprio, con una catena di step bloccati.
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
