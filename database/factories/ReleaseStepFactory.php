<?php

namespace Database\Factories;

use App\Enums\ReleaseStepStatus;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseStep>
 */
class ReleaseStepFactory extends Factory
{
    /**
     * Gli stessi passaggi del catalogo delle definizioni: uno snapshot plausibile
     * e la copia di un processo plausibile.
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
     * Ultima posizione assegnata per release.
     *
     * Stesso motivo di `StepDefinitionFactory`: una `count(n)->create()` costruisce
     * tutte le istanze prima di salvarne una sola, e il massimo letto dal database
     * sarebbe identico per tutte.
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
            'release_id' => Release::factory(),
            'name' => $entry['name'],
            'instructions' => $entry['instructions'],
            'role_id' => Role::factory(),
            'assigned_user_id' => User::factory(),
            'status' => ReleaseStepStatus::Blocked,
        ];
    }

    /**
     * Completa la posizione e congela il nome del ruolo.
     *
     * `role_name` non e un dato indipendente: e la copia del nome del ruolo
     * collegato. Lasciarlo scrivere a caso produrrebbe snapshot che l'avvio non
     * puo generare, e i test scritti su quelli dimostrerebbero il contrario di
     * cio che serve.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ReleaseStep $step): void {
            if ($step->getAttribute('position') === null) {
                $key = (string) $step->release_id;

                $stored = (int) ReleaseStep::query()
                    ->where('release_id', $key)
                    ->max('position');

                $step->position = self::$lastPosition[$key] = max($stored, self::$lastPosition[$key] ?? 0) + 1;
            }

            if ($step->getAttribute('role_name') === null) {
                $step->role_name = (string) Role::query()->whereKey($step->role_id)->value('name');
            }
        });
    }

    /**
     * Step attivo: quello su cui la release e ferma, e l'unico chiudibile.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReleaseStepStatus::Active,
        ]);
    }

    /**
     * Step bloccato: il suo turno non e ancora arrivato.
     */
    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReleaseStepStatus::Blocked,
        ]);
    }

    /**
     * Step gia chiuso, con autore e istante della chiusura.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReleaseStepStatus::Completed,
            /*
             * Closure e non `$attributes['assigned_user_id']` diretto: allo stato
             * gli attributi arrivano **non espansi**, quindi li quel valore e
             * ancora l'oggetto factory e produrrebbe una seconda persona diversa
             * dal responsabile. Chi chiude uno step e il suo responsabile.
             */
            'completed_by' => fn (array $attributes): string => (string) $attributes['assigned_user_id'],
            'completed_at' => now(),
        ]);
    }
}
