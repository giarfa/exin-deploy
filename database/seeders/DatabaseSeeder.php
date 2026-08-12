<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dataset dimostrativo del team e della configurazione di processo.
 *
 * Qui vivono membri, ruoli funzionali, progetti e mappature ruolo -> persona:
 * lo scenario di esecuzione (template di workflow, release a meta catena e
 * release conclusa) appartiene a US-011.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Password condivisa dei soli account dimostrativi in sviluppo.
     */
    private const DEMO_PASSWORD = 'Rilascio-2026!';

    /**
     * Membri fissi del team, con nomi coerenti con i mockup della superficie operativa.
     *
     * @var list<array{name: string, email: string, level: UserLevel, is_active: bool}>
     */
    private const TEAM = [
        [
            'name' => 'Francesco Giarola',
            'email' => 'f.giarola@gruppoexcellence.com',
            'level' => UserLevel::Admin,
            'is_active' => true,
        ],
        [
            'name' => 'Luca Serra',
            'email' => 'l.serra@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Marta Bellini',
            'email' => 'm.bellini@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Davide Rossi',
            'email' => 'd.rossi@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Chiara Fumagalli',
            'email' => 'c.fumagalli@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Paolo Venturi',
            'email' => 'p.venturi@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => false,
        ],
    ];

    /**
     * Ruoli funzionali del processo di rilascio.
     *
     * @var list<array{name: string, description: string}>
     */
    private const ROLES = [
        ['name' => 'Dev Lead', 'description' => 'Responsabile tecnico del codice che entra nel rilascio.'],
        ['name' => 'QA', 'description' => 'Verifica funzionale prima della consegna in produzione.'],
        ['name' => 'DevOps', 'description' => 'Prepara ambienti e infrastruttura, esegue la consegna.'],
        ['name' => 'PM', 'description' => 'Coordina il rilascio e comunica con gli stakeholder.'],
        ['name' => 'Security', 'description' => 'Valuta i rischi di sicurezza introdotti dal rilascio.'],
    ];

    /**
     * Progetti su cui il team rilascia.
     *
     * @var list<array{name: string, slug: string, description: string}>
     */
    private const PROJECTS = [
        [
            'name' => 'Portale Clienti',
            'slug' => 'portale-clienti',
            'description' => 'Area riservata dei clienti: anagrafiche, documenti e richieste di assistenza.',
        ],
        [
            'name' => 'Gestionale Magazzino',
            'slug' => 'gestionale-magazzino',
            'description' => 'Giacenze, movimentazioni e integrazione con i corrieri.',
        ],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedTeam();
        $this->seedRoles();
        $this->seedProjects();
    }

    /**
     * Membri del team, con credenziali note per lo sviluppo.
     */
    private function seedTeam(): void
    {
        foreach (self::TEAM as $member) {
            User::factory()->create([
                ...$member,
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]);
        }
    }

    /**
     * Catalogo dei ruoli funzionali, base della configurazione di processo.
     */
    private function seedRoles(): void
    {
        foreach (self::ROLES as $role) {
            Role::factory()->create($role);
        }
    }

    /**
     * Progetti dimostrativi, contenitori delle release.
     */
    private function seedProjects(): void
    {
        foreach (self::PROJECTS as $project) {
            Project::factory()->create($project);
        }
    }
}
