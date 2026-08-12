<?php

namespace Database\Seeders;

use App\Enums\FieldType;
use App\Enums\UserLevel;
use App\Models\DefaultRoleAssignment;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dataset dimostrativo del team e della configurazione di processo.
 *
 * Qui vivono membri, ruoli funzionali, template di workflow, progetti e mappature
 * ruolo -> persona: lo scenario di **esecuzione** (release a meta catena e
 * release conclusa) appartiene a US-011.
 *
 * La mappatura dimostrativa copre tutti i ruoli previsti dal template
 * predefinito: lo stato iniziale e quello sano. Per vedere la segnalazione dei
 * ruoli scoperti basta rimuovere un responsabile dalla pagina dei responsabili.
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
     * Processo di rilascio dimostrativo: cinque step ordinati, con i campi che
     * ciascun responsabile deve fornire per chiuderlo.
     *
     * Tutti e quattro i tipi di campo sono rappresentati, con un misto di
     * obbligatori e facoltativi. Il ruolo `PM` resta non usato: e realistico, e
     * dimostra che non ogni ruolo del catalogo compare in ogni processo.
     *
     * @var list<array{name: string, role: string, instructions: string, fields: list<array{label: string, type: FieldType, required: bool, help: string|null}>}>
     */
    private const STANDARD_STEPS = [
        [
            'name' => 'Preparazione del codice',
            'role' => 'Dev Lead',
            'instructions' => 'Verifica che il ramo di rilascio sia allineato e che la pipeline sia verde. Indica la versione che stai rilasciando.',
            'fields' => [
                ['label' => 'Versione rilasciata', 'type' => FieldType::ShortText, 'required' => true, 'help' => 'Il numero di versione o il tag consegnato.'],
                ['label' => 'Link alla pipeline', 'type' => FieldType::Link, 'required' => true, 'help' => 'Indirizzo dell\'esecuzione che ha prodotto il pacchetto.'],
                ['label' => 'Note di preparazione', 'type' => FieldType::LongText, 'required' => false, 'help' => 'Cosa deve sapere chi esegue lo step successivo.'],
            ],
        ],
        [
            'name' => 'Verifica funzionale',
            'role' => 'QA',
            'instructions' => 'Esegui i controlli funzionali sulle aree toccate dal rilascio e riporta l\'esito.',
            'fields' => [
                ['label' => 'Esito della verifica', 'type' => FieldType::LongText, 'required' => true, 'help' => 'Cosa e stato provato e con quale risultato.'],
                ['label' => 'Link al report di test', 'type' => FieldType::Link, 'required' => false, 'help' => null],
                ['label' => 'Regressioni verificate', 'type' => FieldType::Confirmation, 'required' => true, 'help' => null],
            ],
        ],
        [
            'name' => 'Valutazione di sicurezza',
            'role' => 'Security',
            'instructions' => 'Valuta i rischi introdotti dal rilascio e controlla le dipendenze aggiornate.',
            'fields' => [
                ['label' => 'Rischi rilevati', 'type' => FieldType::LongText, 'required' => true, 'help' => 'Anche "nessuno" e una risposta, purche esplicita.'],
                ['label' => 'Verifica delle dipendenze eseguita', 'type' => FieldType::Confirmation, 'required' => true, 'help' => null],
            ],
        ],
        [
            'name' => 'Preparazione dell\'ambiente',
            'role' => 'DevOps',
            'instructions' => 'Prepara l\'ambiente di destinazione, esegui il backup e tieni pronto il piano di rientro.',
            'fields' => [
                ['label' => 'Ambiente di destinazione', 'type' => FieldType::ShortText, 'required' => true, 'help' => null],
                ['label' => 'Backup eseguito', 'type' => FieldType::Confirmation, 'required' => true, 'help' => null],
                ['label' => 'Link al piano di rientro', 'type' => FieldType::Link, 'required' => false, 'help' => 'Come si torna indietro se qualcosa va storto.'],
            ],
        ],
        [
            'name' => 'Consegna in produzione',
            'role' => 'DevOps',
            'instructions' => 'Esegui la consegna, verifica che il servizio risponda e pubblica il changelog.',
            'fields' => [
                ['label' => 'Consegna completata', 'type' => FieldType::Confirmation, 'required' => true, 'help' => null],
                ['label' => 'Link al changelog pubblicato', 'type' => FieldType::Link, 'required' => false, 'help' => null],
                ['label' => 'Note di consegna', 'type' => FieldType::LongText, 'required' => false, 'help' => 'Cosa deve sapere chi presidia le ore successive.'],
            ],
        ],
    ];

    /**
     * Processo ridotto e **disattivato**: dimostra il filtro sull'elenco, la
     * sostituibilita del template su un progetto e il fatto che un template
     * disattivato non e proponibile.
     *
     * @var list<array{name: string, role: string, instructions: string}>
     */
    private const URGENT_STEPS = [
        [
            'name' => 'Correzione e verifica rapida',
            'role' => 'Dev Lead',
            'instructions' => 'Prepara la correzione minima e verificala sull\'area interessata.',
        ],
        [
            'name' => 'Approvazione del rilascio urgente',
            'role' => 'Security',
            'instructions' => 'Valuta se il rischio della correzione e inferiore a quello di attendere il rilascio programmato.',
        ],
        [
            'name' => 'Consegna immediata',
            'role' => 'DevOps',
            'instructions' => 'Consegna in produzione e comunica l\'intervento al team.',
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
        // Prima dei progetti: cosi nascono gia associati al processo predefinito.
        $this->seedWorkflowTemplates();
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
     * Template di workflow: la definizione del processo di rilascio.
     *
     * Il seeder descrive lo stato finale voluto con dati espliciti e non simula
     * il percorso dell'interfaccia — stessa convenzione delle mappature. Il flag
     * di predefinito e scritto direttamente perche qui esiste un solo template
     * candidato: nell'applicazione passa dalla Action che ne garantisce l'unicita.
     */
    private function seedWorkflowTemplates(): void
    {
        $roles = Role::pluck('id', 'name');

        $standard = WorkflowTemplate::factory()->create([
            'name' => 'Rilascio standard',
            'description' => 'Il percorso completo, dalla preparazione del codice alla consegna in produzione.',
            'is_active' => true,
            'is_default' => true,
        ]);

        foreach (self::STANDARD_STEPS as $position => $step) {
            $stepDefinition = StepDefinition::factory()->create([
                'workflow_template_id' => $standard->id,
                'position' => $position + 1,
                'name' => $step['name'],
                'instructions' => $step['instructions'],
                'role_id' => $roles[$step['role']],
            ]);

            foreach ($step['fields'] as $index => $field) {
                FieldDefinition::factory()->create([
                    'step_definition_id' => $stepDefinition->id,
                    'position' => $index + 1,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'is_required' => $field['required'],
                    'help_text' => $field['help'],
                ]);
            }
        }

        $urgent = WorkflowTemplate::factory()->inactive()->create([
            'name' => 'Rilascio urgente',
            'description' => 'Percorso ridotto per le correzioni che non possono attendere il rilascio programmato.',
        ]);

        foreach (self::URGENT_STEPS as $position => $step) {
            StepDefinition::factory()->create([
                'workflow_template_id' => $urgent->id,
                'position' => $position + 1,
                'name' => $step['name'],
                'instructions' => $step['instructions'],
                'role_id' => $roles[$step['role']],
            ]);
        }
    }

    /**
     * Progetti dimostrativi, contenitori delle release.
     *
     * Nascono associati al template predefinito scrivendo la colonna in modo
     * esplicito, e non tramite `CreateProject`: il seeder dichiara lo stato
     * finale invece di ripercorrere il comportamento dell'interfaccia.
     */
    private function seedProjects(): void
    {
        $defaultTemplate = WorkflowTemplate::where('is_default', true)->value('id');

        foreach (self::PROJECTS as $project) {
            Project::factory()->create([...$project, 'workflow_template_id' => $defaultTemplate]);
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
