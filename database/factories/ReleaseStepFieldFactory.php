<?php

namespace Database\Factories;

use App\Enums\FieldType;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseStepField>
 */
class ReleaseStepFieldFactory extends Factory
{
    /**
     * Le stesse informazioni del catalogo delle definizioni: uno snapshot e la
     * copia di cio che il template chiedeva.
     *
     * @var list<array{label: string, type: FieldType, is_required: bool, help_text: string|null}>
     */
    private const CATALOGUE = [
        [
            'label' => 'Versione rilasciata',
            'type' => FieldType::ShortText,
            'is_required' => true,
            'help_text' => 'Il numero di versione o il tag consegnato.',
        ],
        [
            'label' => 'Link alla pipeline',
            'type' => FieldType::Link,
            'is_required' => true,
            'help_text' => 'Indirizzo dell\'esecuzione che ha prodotto il pacchetto.',
        ],
        [
            'label' => 'Note di preparazione',
            'type' => FieldType::LongText,
            'is_required' => false,
            'help_text' => 'Cosa deve sapere chi esegue lo step successivo.',
        ],
        [
            'label' => 'Regressioni verificate',
            'type' => FieldType::Confirmation,
            'is_required' => true,
            'help_text' => null,
        ],
        [
            'label' => 'Esito della verifica',
            'type' => FieldType::LongText,
            'is_required' => true,
            'help_text' => 'Cosa e stato provato e con quale risultato.',
        ],
        [
            'label' => 'Backup eseguito',
            'type' => FieldType::Confirmation,
            'is_required' => true,
            'help_text' => null,
        ],
    ];

    /**
     * Ultima posizione assegnata per step.
     *
     * @var array<string, int>
     */
    private static array $lastPosition = [];

    /**
     * Progressivo dei campi generati, per scorrere il catalogo.
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
            'release_step_id' => ReleaseStep::factory(),
            'label' => $entry['label'],
            'type' => $entry['type'],
            'is_required' => $entry['is_required'],
            'help_text' => $entry['help_text'],
            // Il valore nasce vuoto: lo scrive la chiusura dello step (US-005).
            'value' => null,
        ];
    }

    /**
     * Assegna la posizione in coda allo step, se il chiamante non ne ha indicata una.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ReleaseStepField $field): void {
            if ($field->getAttribute('position') !== null) {
                return;
            }

            $key = (string) $field->release_step_id;

            $stored = (int) ReleaseStepField::query()
                ->where('release_step_id', $key)
                ->max('position');

            $field->position = self::$lastPosition[$key] = max($stored, self::$lastPosition[$key] ?? 0) + 1;
        });
    }

    /**
     * Campo di testo breve: una riga, per un valore puntuale.
     */
    public function shortText(): static
    {
        return $this->state(fn (array $attributes): array => [
            'label' => 'Ambiente di destinazione',
            'type' => FieldType::ShortText,
        ]);
    }

    /**
     * Campo di testo lungo: una spiegazione, non un valore.
     */
    public function longText(): static
    {
        return $this->state(fn (array $attributes): array => [
            'label' => 'Rischi rilevati',
            'type' => FieldType::LongText,
        ]);
    }

    /**
     * Campo collegamento: rimanda alla prova esterna di quanto dichiarato.
     */
    public function link(): static
    {
        return $this->state(fn (array $attributes): array => [
            'label' => 'Link al report di test',
            'type' => FieldType::Link,
        ]);
    }

    /**
     * Campo di conferma: una spunta che dichiara un controllo eseguito.
     */
    public function confirmation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'label' => 'Verifica delle dipendenze eseguita',
            'type' => FieldType::Confirmation,
            'help_text' => null,
        ]);
    }

    /**
     * Campo facoltativo: la sua assenza non impedira di chiudere lo step.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_required' => false,
        ]);
    }
}
