<?php

namespace Tests\Feature\Releases;

use App\Enums\FieldType;
use App\Enums\ReleaseStepStatus;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Il PRD indica l'N+1 come rischio strutturale delle catene annidate, e il form di
 * chiusura e il punto in cui si presenta: uno step del template dimostrativo ha
 * quattordici campi, ognuno con etichetta, tipo, obbligatorieta e testo di aiuto da
 * leggere.
 *
 * Come in `StartReleaseQueryBudgetTest`, il test non fissa un numero assoluto — lo
 * cambierebbe ogni ottimizzazione legittima — ma l'invariante che conta: **lo
 * stesso** costo su uno step con un campo e su uno con quattordici.
 */
class CloseStepQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_the_closing_form_costs_the_same_on_a_short_and_on_a_long_form(): void
    {
        $short = $this->activeStepWith(fields: 1);
        $long = $this->activeStepWith(fields: 14);

        $shortCost = $this->queriesWhile(fn () => $this->readClosingForm($short));
        $longCost = $this->queriesWhile(fn () => $this->readClosingForm($long));

        $this->assertSame(
            $shortCost,
            $longCost,
            "La lettura e costata {$shortCost} query su un campo e {$longCost} su quattordici: manca un eager loading."
        );
    }

    public function test_composing_the_closing_rules_does_not_query_per_field(): void
    {
        $step = ReleaseStep::query()
            ->whereKey($this->activeStepWith(fields: 14)->getKey())
            ->with('fields')
            ->firstOrFail();

        $queries = $this->queriesWhile(function () use ($step): void {
            $step->closingRules();
            $step->closingAttributes();
        });

        // Le regole si compongono dai campi gia caricati: una query per campo qui
        // ricomparirebbe a ogni rifiuto di validazione, cioe proprio quando la
        // schermata e piu lenta a rispondere.
        $this->assertSame(0, $queries, "La composizione delle regole ha eseguito {$queries} query.");
    }

    /**
     * Legge quanto serve alla schermata di chiusura: lo step, i suoi campi, la
     * release con il progetto, il responsabile e lo step successivo.
     */
    private function readClosingForm(ReleaseStep $step): void
    {
        $loaded = ReleaseStep::query()
            ->whereKey($step->getKey())
            ->with(['fields', 'release.project', 'assignedUser'])
            ->firstOrFail();

        $next = $loaded->nextStep();
        $next?->loadMissing('assignedUser:id,name');

        // Gli attributi vengono raccolti e non solo sfiorati: e la lettura a poter
        // far scattare un caricamento pigro, cioe la query che il test misura.
        collect([
            $loaded->name,
            $loaded->role_name,
            $loaded->assignedUser->name,
            $loaded->release->label,
            $loaded->release->project->name,
            $next?->assignedUser->name ?? '',
            $loaded->fields->map(fn (ReleaseStepField $field): string => implode('/', [
                $field->label,
                $field->type->value,
                (string) $field->is_required,
                (string) $field->help_text,
                (string) $field->value,
            ]))->implode(' '),
        ])->implode(' ');
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
     * Step attivo con il numero di campi indicato, su una catena di due step.
     */
    private function activeStepWith(int $fields): ReleaseStep
    {
        $release = Release::factory()->create();
        $responsible = User::factory()->create();

        $step = ReleaseStep::factory()->for($release)->create([
            'position' => 1,
            'status' => ReleaseStepStatus::Active,
            'assigned_user_id' => $responsible->id,
        ]);

        ReleaseStep::factory()->for($release)->create([
            'position' => 2,
            'status' => ReleaseStepStatus::Blocked,
            'assigned_user_id' => $responsible->id,
        ]);

        for ($position = 1; $position <= $fields; $position++) {
            ReleaseStepField::factory()->for($step)->create([
                'position' => $position,
                'type' => FieldType::ShortText,
                'is_required' => true,
            ]);
        }

        return $step;
    }
}
