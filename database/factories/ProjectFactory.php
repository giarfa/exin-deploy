<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Nomi di progetto plausibili per un gruppo che rilascia software interno.
     *
     * @var list<string>
     */
    private const NAMES = [
        'Portale Clienti',
        'Gestionale Magazzino',
        'Sito Istituzionale',
        'App Agenti',
        'Intranet',
        'Fatturazione Elettronica',
    ];

    /**
     * Progressivo dei progetti generati: lo slug e unico a livello di schema, e
     * un contatore deterministico evita collisioni senza rinunciare a nomi
     * leggibili.
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
        $name = self::NAMES[$index % count(self::NAMES)];
        $suffix = $index >= count(self::NAMES) ? ' '.($index + 1) : '';

        return [
            'name' => $name.$suffix,
            'slug' => Str::slug($name.$suffix),
            'description' => 'Rilasci del progetto '.$name.$suffix.'.',
            'is_active' => true,
        ];
    }

    /**
     * Progetto con un processo di rilascio associato.
     *
     * Non e lo stato predefinito: senza questa scelta esplicita i progetti nascono
     * senza template, cosi che i test scritti prima di US-003 continuino a
     * descrivere lo stesso scenario.
     */
    public function withTemplate(?WorkflowTemplate $template = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'workflow_template_id' => $template instanceof WorkflowTemplate
                ? $template->id
                : WorkflowTemplateFactory::new()->withSteps(),
        ]);
    }

    /**
     * Progetto disattivato: resta consultabile nello storico, ma non accoglie
     * nuove release.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
