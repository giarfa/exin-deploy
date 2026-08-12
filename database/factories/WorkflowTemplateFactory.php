<?php

namespace Database\Factories;

use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTemplate>
 */
class WorkflowTemplateFactory extends Factory
{
    /**
     * Processi di rilascio plausibili in un team che gestisce piu progetti.
     *
     * @var list<array{name: string, description: string}>
     */
    private const CATALOGUE = [
        ['name' => 'Rilascio standard', 'description' => 'Il percorso completo, dalla preparazione del codice alla consegna in produzione.'],
        ['name' => 'Rilascio urgente', 'description' => 'Percorso ridotto per le correzioni che non possono attendere il rilascio programmato.'],
        ['name' => 'Rilascio infrastrutturale', 'description' => 'Interventi su ambienti e infrastruttura, senza modifiche applicative.'],
        ['name' => 'Aggiornamento delle dipendenze', 'description' => 'Allineamento delle librerie con verifica di sicurezza e regressione.'],
        ['name' => 'Rilascio in ambiente di collaudo', 'description' => 'Consegna al collaudo del cliente, prima del passaggio in produzione.'],
    ];

    /**
     * Progressivo dei template generati.
     *
     * Il nome e unico a livello di schema: un contatore deterministico evita
     * collisioni quando un test crea piu template del catalogo, senza rinunciare
     * a nomi di dominio leggibili.
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
        $entry = self::CATALOGUE[$index % count(self::CATALOGUE)];
        $suffix = $index >= count(self::CATALOGUE) ? ' '.($index + 1) : '';

        return [
            'name' => $entry['name'].$suffix,
            'description' => $entry['description'],
            'is_active' => true,
            'is_default' => false,
        ];
    }

    /**
     * Template disattivato: non proponibile su un progetto, ma ancora leggibile
     * dove e gia associato.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Template predefinito, proposto alla creazione di un nuovo progetto.
     *
     * Lo stato scrive il flag direttamente: e una scorciatoia per predisporre
     * dati di prova. Nell'applicazione il flag passa **solo** dalla Action
     * `SetDefaultWorkflowTemplate`, che garantisce l'unicita del predefinito.
     */
    public function isDefault(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}
