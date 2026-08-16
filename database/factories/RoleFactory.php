<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Ruoli funzionali plausibili in un processo di rilascio.
     *
     * @var list<array{name: string, description: string}>
     */
    private const CATALOGUE = [
        ['name' => 'Dev Lead', 'description' => 'Responsabile tecnico del codice che entra nel rilascio.'],
        ['name' => 'QA', 'description' => 'Verifica funzionale prima della consegna in produzione.'],
        ['name' => 'DevOps', 'description' => 'Prepara ambienti e infrastruttura, esegue la consegna.'],
        ['name' => 'PM', 'description' => 'Coordina il rilascio e comunica con gli stakeholder.'],
        ['name' => 'Security', 'description' => 'Valuta i rischi di sicurezza introdotti dal rilascio.'],
        ['name' => 'Release Manager', 'description' => 'Presidia il processo e autorizza il passaggio finale.'],
        ['name' => 'Architetto', 'description' => 'Valuta l\'impatto architetturale delle modifiche.'],
        ['name' => 'Supporto', 'description' => 'Prepara il presidio post-rilascio e la documentazione utente.'],
    ];

    /**
     * Progressivo dei ruoli generati.
     *
     * Il nome del ruolo e unico a livello di schema: un contatore deterministico
     * evita collisioni quando un test crea piu ruoli del catalogo, senza rinunciare
     * a nomi di dominio leggibili (Faker produrrebbe parole senza significato).
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
        ];
    }

    /**
     * Ruolo disattivato: non proponibile in nuove assegnazioni, ma ancora
     * leggibile dove e gia stato usato.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
