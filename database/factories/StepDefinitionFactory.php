<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StepDefinition>
 */
class StepDefinitionFactory extends Factory
{
    /**
     * Passaggi plausibili di un processo di rilascio, nell'ordine in cui un team
     * li affronta davvero.
     *
     * @var list<array{name: string, instructions: string}>
     */
    private const CATALOGUE = [
        [
            'name' => 'Preparazione del codice',
            'instructions' => 'Verifica che il ramo di rilascio sia allineato e che la pipeline sia verde. Indica la versione che stai rilasciando.',
        ],
        [
            'name' => 'Verifica funzionale',
            'instructions' => 'Esegui i controlli funzionali sulle aree toccate dal rilascio e riporta l\'esito, incluse le regressioni verificate.',
        ],
        [
            'name' => 'Valutazione di sicurezza',
            'instructions' => 'Valuta i rischi introdotti dal rilascio e controlla le dipendenze aggiornate.',
        ],
        [
            'name' => 'Preparazione dell\'ambiente',
            'instructions' => 'Prepara l\'ambiente di destinazione, esegui il backup e tieni pronto il piano di rientro.',
        ],
        [
            'name' => 'Consegna in produzione',
            'instructions' => 'Esegui la consegna, verifica che il servizio risponda e pubblica il changelog.',
        ],
        [
            'name' => 'Presidio post-rilascio',
            'instructions' => 'Tieni monitorati errori e segnalazioni nelle ore successive alla consegna.',
        ],
    ];

    /**
     * Ultima posizione assegnata per template.
     *
     * Serve perche una `count(n)->create()` costruisce tutte le istanze **prima**
     * di salvarne una sola: leggere il massimo dal database darebbe lo stesso
     * valore a tutte, e l'indice unico (template, posizione) rifiuterebbe la
     * seconda scrittura.
     *
     * @var array<string, int>
     */
    private static array $lastPosition = [];

    /**
     * Progressivo degli step generati, per scorrere il catalogo.
     */
    private static int $generated = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $entry = self::CATALOGUE[self::$generated++ % count(self::CATALOGUE)];

        return [
            'workflow_template_id' => WorkflowTemplate::factory(),
            'name' => $entry['name'],
            'instructions' => $entry['instructions'],
            'role_id' => Role::factory(),
        ];
    }

    /**
     * Assegna la posizione in coda al template, se il chiamante non ne ha indicata una.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (StepDefinition $step): void {
            if ($step->getAttribute('position') !== null) {
                return;
            }

            $key = (string) $step->workflow_template_id;

            $stored = (int) StepDefinition::query()
                ->where('workflow_template_id', $key)
                ->max('position');

            $step->position = self::$lastPosition[$key] = max($stored, self::$lastPosition[$key] ?? 0) + 1;
        });
    }

    /**
     * Step senza istruzioni: lecito, ma e il caso in cui il processo smette di
     * essere autoesplicativo per chi lo eredita.
     */
    public function withoutInstructions(): static
    {
        return $this->state(fn (array $attributes): array => [
            'instructions' => null,
        ]);
    }
}
