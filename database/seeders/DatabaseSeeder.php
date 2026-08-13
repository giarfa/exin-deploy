<?php

namespace Database\Seeders;

use App\Actions\Releases\CloseStep;
use App\Actions\Releases\StartRelease;
use App\Enums\FieldType;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Enums\UserLevel;
use App\Models\DefaultRoleAssignment;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Carbon\CarbonInterface;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dataset dimostrativo: il team, la configurazione di processo e tre rilasci che
 * coprono le forme che le schermate devono saper mostrare.
 *
 * **Due modi di seminare, e non e un'incoerenza.** La configurazione — membri,
 * ruoli, template, progetti, mappature — e uno **stato**: si dichiara con dati
 * espliciti, che e piu chiaro che ricostruirlo passando dall'interfaccia. I
 * rilasci no: un rilascio e un **processo**, e il suo stato *e* la sequenza di
 * transizioni che lo ha prodotto. Per questo `seedReleases()` chiama le Action
 * reali (`StartRelease`, `CloseStep`) invece di scrivere righe.
 *
 * Scriverle a mano significherebbe replicare qui lo snapshot, la risoluzione dei
 * responsabili, l'invariante dello step attivo unico, i valori congelati e i
 * payload di cinque tipi di evento: il dominio in un secondo posto, destinato a
 * divergere al primo cambiamento. E il registro delle transizioni e proprio cio
 * che una scrittura a mano falsificherebbe meglio — righe di forma giusta e
 * contenuto inventato.
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
     * Valori forniti alla chiusura degli step, indicizzati per **etichetta** del
     * campo.
     *
     * Per etichetta e non per posizione: la posizione e un dettaglio del template e
     * cambia riordinandolo, l'etichetta e la domanda a cui il valore risponde. Un
     * campo che il template aggiungesse in mezzo non spostherebbe le risposte
     * altrove.
     *
     * Nessun lorem ipsum: sono frasi di rilascio vere, indirizzi plausibili e
     * conferme spuntate, come chiede il criterio di accettazione.
     *
     * @var array<string, string>
     */
    private const STEP_VALUES = [
        'Versione rilasciata' => 'v2.4.0',
        'Link alla pipeline' => 'https://ci.gruppoexcellence.com/portale-clienti/build/1842',
        'Note di preparazione' => 'Migrazione della tabella documenti inclusa: va eseguita prima del riavvio dei worker.',
        'Esito della verifica' => 'Provati caricamento documenti, ricerca e apertura richieste su Chrome e Safari. Nessuna regressione sulle aree toccate.',
        'Link al report di test' => 'https://qa.gruppoexcellence.com/report/portale-clienti-2426',
        'Regressioni verificate' => '1',
        'Rischi rilevati' => 'Nessuno: le dipendenze aggiornate riguardano solo il livello di presentazione e non toccano autenticazione o dati personali.',
        'Verifica delle dipendenze eseguita' => '1',
        'Ambiente di destinazione' => 'produzione-01',
        'Backup eseguito' => '1',
        'Link al piano di rientro' => 'https://wiki.gruppoexcellence.com/rilasci/piano-di-rientro',
        'Consegna completata' => '1',
        'Link al changelog pubblicato' => 'https://portale.gruppoexcellence.com/novita',
        'Note di consegna' => 'Servizio verificato dopo la consegna: tempi di risposta nella norma, nessun errore nei log della prima ora.',
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
        // Per ultimo: un rilascio ha bisogno del progetto, del processo e dei
        // responsabili gia in piedi — sono le precondizioni che `StartRelease`
        // verifica, e seminarlo prima produrrebbe un rifiuto invece di una release.
        $this->seedReleases();
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

    /**
     * Lo scenario di esecuzione: tre rilasci, ognuno in una forma diversa.
     *
     * | Release | Progetto | Cosa dimostra |
     * | --- | --- | --- |
     * | `v2.3.0` | Portale Clienti | rilascio concluso: catena tutta chiusa, registro completo |
     * | `v2.4.0` | Portale Clienti | rilascio a meta catena: primo step chiuso, secondo attivo |
     * | `2026.08.1` | Gestionale Magazzino | rilascio appena avviato, fermo sul **primo** step |
     *
     * La terza non e un di piu: e l'unica forma in cui lo step attivo e il primo
     * della catena, dove `ReleaseStep::activationInstant()` non ha un precedente da
     * cui leggere l'istante e ripiega su `release.started_at`. E il ramo che ogni
     * rilascio nuovo percorre, e un ambiente dimostrativo che non lo contenesse lo
     * lascerebbe non verificabile a mano.
     *
     * **Nessuna release sul template disattivato.** "Rilascio urgente" resta senza:
     * `StartRelease` rifiuta un processo disattivato, e un ambiente dimostrativo che
     * contenesse uno stato irriproducibile dall'applicazione direbbe una bugia.
     *
     * **Nessun tentativo non autorizzato seminato.** Il registro dimostrativo porta
     * le transizioni di processo ma non la traccia di qualcuno che ha provato a fare
     * cio che non poteva, con il nome di una persona del team di esempio: in un
     * ambiente condiviso e una riga che si presta a essere letta male. Chi vuole
     * vederla la produce aprendo lo step di un altro.
     */
    private function seedReleases(): void
    {
        $customerPortal = Project::where('slug', 'portale-clienti')->firstOrFail();
        $warehouse = Project::where('slug', 'gestionale-magazzino')->firstOrFail();
        $owner = User::where('email', 'f.giarola@gruppoexcellence.com')->firstOrFail();

        /*
         * Gli istanti sono spostati indietro nel tempo, e non e un dettaglio
         * estetico: appena seminate, tutte le schermate direbbero "aperto da 0
         * secondi" e lo storico avrebbe una sola data, cioe non mostrerebbe ne le
         * durate ne l'ordinamento che le due viste esistono per dare.
         */
        $delivered = $this->releaseThroughTheChain($customerPortal, 'v2.3.0', $owner, closeSteps: 5, startedAt: now()->subDays(9));
        $this->rewindRelease($delivered, now()->subDays(9), now()->subDays(8));

        $inProgress = $this->releaseThroughTheChain($customerPortal, 'v2.4.0', $owner, closeSteps: 1, startedAt: now()->subDays(2));
        $this->rewindRelease($inProgress, now()->subDays(2));

        $justStarted = $this->releaseThroughTheChain($warehouse, '2026.08.1', $owner, closeSteps: 0, startedAt: now()->subHours(5));
        $this->rewindRelease($justStarted, now()->subHours(5));
    }

    /**
     * Avvia una release e ne chiude i primi `closeSteps` passaggi, ognuno per mano
     * del proprio responsabile.
     *
     * Per mano del responsabile e non dell'amministratore: `CloseStep` accetterebbe
     * entrambi, ma il registro dimostrativo racconterebbe un rilascio in cui una
     * sola persona ha fatto tutto — cioe l'opposto del processo che lo strumento
     * esiste per orchestrare.
     */
    private function releaseThroughTheChain(
        Project $project,
        string $label,
        User $starter,
        int $closeSteps,
        CarbonInterface $startedAt,
    ): Release {
        $release = app(StartRelease::class)->handle($project, $label, $starter);

        $release->forceFill(['started_at' => $startedAt])->save();

        for ($closed = 0; $closed < $closeSteps; $closed++) {
            $step = $release->steps()
                ->with('fields')
                ->where('status', ReleaseStepStatus::Active)
                ->firstOrFail();

            app(CloseStep::class)->handle($step, $this->valuesFor($step), $step->assignedUser);
        }

        return $release->refresh();
    }

    /**
     * Distribuisce le chiusure fra l'avvio e la consegna.
     *
     * Le Action scrivono `now()` — e giusto che lo facciano — quindi in un ambiente
     * appena seminato tutti gli istanti coinciderebbero e le schermate mostrerebbero
     * una catena chiusa in zero secondi. Qui le date vengono riscritte **dopo**, e
     * con `forceFill` perche `completed_at` non e assegnabile in massa: la scrive
     * solo `CloseStep`, e un `update` la lascerebbe cadere in silenzio (vedi
     * `.ai/rules/tests.md`).
     */
    private function rewindRelease(Release $release, CarbonInterface $from, ?CarbonInterface $until = null): void
    {
        $closed = $release->steps()->where('status', ReleaseStepStatus::Completed)->orderBy('position')->get();

        if ($closed->isEmpty()) {
            return;
        }

        $until ??= now();
        $span = $from->diffInMinutes($until);

        foreach ($closed as $index => $step) {
            $step->forceFill([
                'completed_at' => $from->copy()->addMinutes((int) ($span * ($index + 1) / $closed->count())),
            ])->save();
        }

        if ($release->status === ReleaseStatus::Completed) {
            $release->forceFill(['completed_at' => $closed->last()->fresh()->completed_at])->save();
        }
    }

    /**
     * Valori da fornire per chiudere uno step, presi per etichetta del campo.
     *
     * Il ripiego non e pigrizia: un campo aggiunto al template senza un valore qui
     * deve **impedire** la chiusura come farebbe nell'applicazione, non riceverne
     * uno inventato — a meno che sia facoltativo, e allora resta vuoto.
     *
     * @return array<string, string|null>
     */
    private function valuesFor(ReleaseStep $step): array
    {
        return $step->fields
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => self::STEP_VALUES[$field->label] ?? null,
            ])
            ->all();
    }
}
