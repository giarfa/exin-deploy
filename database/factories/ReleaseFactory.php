<?php

namespace Database\Factories;

use App\Enums\ReleaseStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    /**
     * Etichette di rilascio come le scrive davvero un team: versioni semantiche e
     * numerazioni per data convivono, perche convivono i progetti che le usano.
     *
     * @var list<string>
     */
    private const LABELS = [
        'v2.4.0',
        'v2.4.1',
        '2026.08.1',
        'v3.0.0-rc.1',
        '2026.08.2',
        'v2.5.0',
    ];

    /**
     * Progressivo delle release generate.
     *
     * L'etichetta e unica per progetto a livello di schema: un contatore
     * deterministico evita collisioni quando un test ne crea piu del catalogo,
     * senza rinunciare a etichette leggibili.
     */
    private static int $generated = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = self::$generated++;
        $label = self::LABELS[$index % count(self::LABELS)];
        $suffix = $index >= count(self::LABELS) ? '+'.($index + 1) : '';

        return [
            // Il progetto nasce gia con un processo associato: il template della
            // release viene poi risolto da quello, vedi `configure()`.
            'project_id' => Project::factory()->withTemplate(),
            'label' => $label.$suffix,
            'status' => ReleaseStatus::InProgress,
            'started_by' => User::factory()->admin(),
            'started_at' => now(),
        ];
    }

    /**
     * Risolve il template della release da quello del suo progetto.
     *
     * Non e una comodita: una release che nomina un template diverso da quello
     * del proprio progetto descriverebbe uno stato che l'avvio non puo produrre,
     * e i test scritti su quello dimostrerebbero qualcosa che non esiste. Vale
     * anche quando il progetto arriva dal chiamante (`for($project)`), che e il
     * caso in cui l'incoerenza passerebbe inosservata.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Release $release): void {
            if ($release->getAttribute('workflow_template_id') !== null) {
                return;
            }

            $project = Project::find($release->project_id);

            if ($project === null) {
                return;
            }

            if ($project->workflow_template_id === null) {
                $project->update([
                    'workflow_template_id' => WorkflowTemplate::factory()->withSteps()->create()->id,
                ]);
            }

            $release->workflow_template_id = $project->workflow_template_id;
        });
    }

    /**
     * Release gia conclusa: porta autore e istante della conclusione, gli stessi che
     * `App\Actions\Releases\CloseStep` scrive chiudendo l'ultimo step della catena.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReleaseStatus::Completed,
            /*
             * Closure e non `$attributes['started_by']` diretto: allo stato gli
             * attributi arrivano **non espansi**, quindi li quel valore e ancora
             * l'oggetto factory e produrrebbe una seconda persona diversa. La
             * closure viene valutata dopo l'espansione, quando la chiave contiene
             * gia l'identificativo di chi ha avviato.
             */
            'completed_by' => fn (array $attributes): string => (string) $attributes['started_by'],
            'completed_at' => now(),
        ]);
    }
}
