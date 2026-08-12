<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
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
     * Mappatura predefinita ruolo -> persona valida per il team.
     *
     * @var array<string, string>
     */
    private const DEFAULT_ASSIGNMENTS = [
        'Dev Lead' => 'l.serra@gruppoexcellence.com',
        'QA' => 'm.bellini@gruppoexcellence.com',
        'DevOps' => 'd.rossi@gruppoexcellence.com',
        'PM' => 'c.fumagalli@gruppoexcellence.com',
        'Security' => 'f.giarola@gruppoexcellence.com',
    ];

    /**
     * Scostamenti dalla mappatura predefinita, per progetto.
     *
     * Un progetto eredita la mappatura predefinita intatta, l'altro ha una
     * sostituzione: in sviluppo la differenza fra le due mappature deve essere
     * visibile a colpo d'occhio, altrimenti il comportamento che questa spec
     * introduce resta invisibile.
     *
     * @var array<string, array<string, string>>
     */
    private const PROJECT_OVERRIDES = [
        'gestionale-magazzino' => [
            'QA' => 'd.rossi@gruppoexcellence.com',
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
        $this->seedAssignments();
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

    /**
     * Mappatura predefinita di team e mappature dei singoli progetti.
     *
     * I progetti sono seminati con la loro mappatura esplicita e non tramite
     * l'Action di precompilazione: il seeder descrive lo stato finale voluto,
     * inclusi gli scostamenti, invece di simulare il percorso dell'interfaccia.
     */
    private function seedAssignments(): void
    {
        $roles = Role::pluck('id', 'name');
        $users = User::pluck('id', 'email');

        foreach (self::DEFAULT_ASSIGNMENTS as $roleName => $email) {
            DefaultRoleAssignment::factory()->create([
                'role_id' => $roles[$roleName],
                'user_id' => $users[$email],
            ]);
        }

        foreach (Project::all() as $project) {
            $overrides = self::PROJECT_OVERRIDES[$project->slug] ?? [];

            foreach (self::DEFAULT_ASSIGNMENTS as $roleName => $email) {
                ProjectRoleAssignment::factory()->create([
                    'project_id' => $project->id,
                    'role_id' => $roles[$roleName],
                    'user_id' => $users[$overrides[$roleName] ?? $email],
                ]);
            }
        }
    }
}
