<?php

namespace App\Actions\Releases;

use App\Enums\ReleaseEventAction;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\InactiveProjectCannotStartRelease;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\ProjectWithoutUsableTemplate;
use App\Exceptions\ResponsibleOverrideRefused;
use App\Exceptions\RolesWithoutResponsible;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avvia una release su un progetto, copiando il processo in uno snapshot
 * congelato con i responsabili gia risolti.
 *
 * E l'**unico percorso di avvio**, e il punto in cui la definizione smette di
 * contare: da qui in poi la release ha una forma propria, e modificare,
 * riordinare o disattivare il template non la tocca piu. Un riferimento al posto
 * di una copia avrebbe reso lo storico dei rilasci una funzione dello stato
 * corrente della configurazione, cioe inattendibile.
 *
 * Cosa viene congelato: posizione, nome, istruzioni, nome del ruolo, etichetta,
 * forma, obbligatorieta e testo di aiuto dei campi — piu, da US-013, il fatto che
 * il responsabile sia stato **indicato in avvio** invece che ereditato dalla
 * mappatura del progetto (`release_steps.responsible_overridden`). Cosa resta
 * riferimento vivo: l'**identita** di persone e progetto — se una persona cambia
 * cognome lo storico deve mostrare la persona, non un nome fossile.
 *
 * Gli override si risolvono **qui** e finiscono nello snapshot, come gia i
 * responsabili predefiniti: nessun percorso di esecuzione torna a leggere
 * `project_role_assignments`. E l'override **non scrive** ne quella mappatura ne
 * quella predefinita di team — indicare una persona per un singolo rilascio non
 * cambia la configurazione condivisa, che e esattamente la funzione che US-013
 * esiste per offrire.
 *
 * Tutte le precondizioni sono verificate **prima** di qualsiasi scrittura, e
 * dentro la stessa transazione delle scritture: un rifiuto non lascia una release
 * a meta.
 *
 * Nessun lock pessimistico, a differenza di quanto `.ai/rules/app.md` impone
 * all'avanzamento: li si legge uno stato preesistente e lo si riscrive, e due
 * invii concorrenti produrrebbero due avanzamenti. Qui non c'e stato da leggere,
 * e il doppio invio e chiuso dall'unicita `(project_id, label)` a livello di
 * schema — la seconda transazione fallisce e non lascia nulla dietro di se.
 */
class StartRelease
{
    /**
     * I valori sono dichiarati `mixed` e non `string` perche l'unico percorso di
     * produzione li riceve da una richiesta: e questa Action — non il modulo — il
     * punto in cui gli override vengono autorizzati e validati, quindi la firma
     * deve ammettere cio che un client puo davvero mandare.
     *
     * @param  array<string, mixed>  $responsibleOverrides  identificativo del ruolo => identificativo della persona indicata in avvio; il default `[]` riproduce il comportamento precedente a US-013
     *
     * @throws InactiveProjectCannotStartRelease se il progetto e disattivato
     * @throws ProjectWithoutUsableTemplate se il processo manca, e disattivato o non ha step
     * @throws ResponsibleOverrideRefused se un override nomina un ruolo fuori processo o una persona non ammissibile
     * @throws RolesWithoutResponsible se un ruolo previsto resta senza responsabile
     * @throws InactiveResponsibleOnProject se un responsabile predefinito e disattivato e non e stato sovrascritto
     */
    public function handle(Project $project, string $label, User $actor, array $responsibleOverrides = []): Release
    {
        return DB::transaction(function () use ($project, $label, $actor, $responsibleOverrides): Release {
            /*
             * Il progetto viene **riletto**, non solo ricaricato nelle relazioni.
             *
             * Rileggere le sole relazioni lascerebbe in memoria gli attributi che
             * il chiamante teneva da prima — `is_active` e soprattutto
             * `workflow_template_id`, da cui l'eager loading risale al processo.
             * Un componente che avesse letto il progetto prima di una sostituzione
             * del template ne congelerebbe uno che non e piu il suo, e lo
             * scriverebbe pure in `releases.workflow_template_id`.
             *
             * Un solo caricamento, con tutto cio che serve: da qui in poi nessuna
             * query per step o per campo, qualunque sia la lunghezza della catena.
             */
            $project = Project::query()
                ->whereKey($project->getKey())
                ->with([
                    'workflowTemplate.stepDefinitions.role',
                    'workflowTemplate.stepDefinitions.fieldDefinitions',
                    'assignments.user',
                ])
                ->firstOrFail();

            $template = $this->usableTemplate($project);
            $resolution = $this->responsibles($project, $template, $responsibleOverrides);

            $responsibles = $resolution['byRole'];
            $overridden = $resolution['overriddenRoles'];

            $now = now();

            $release = Release::create([
                'project_id' => $project->id,
                'workflow_template_id' => $template->id,
                'label' => $label,
                'status' => ReleaseStatus::InProgress,
                'started_by' => $actor->id,
                'started_at' => $now,
            ]);

            $steps = [];
            $fields = [];

            foreach ($template->stepDefinitions as $index => $definition) {
                $stepId = (string) Str::uuid7();

                $steps[] = [
                    'id' => $stepId,
                    'release_id' => $release->id,
                    'position' => $index + 1,
                    'name' => $definition->name,
                    'instructions' => $definition->instructions,
                    'role_id' => $definition->role_id,
                    // Il nome del ruolo e congelato: rinominarlo non deve riscrivere
                    // lo storico dei rilasci gia eseguiti.
                    'role_name' => $definition->role->name,
                    'assigned_user_id' => $responsibles[$definition->role_id]->id,
                    // Vero solo dove la persona risolta differisce da quella che la
                    // mappatura avrebbe dato: un selettore lasciato sul predefinito
                    // non e una sovrascrittura.
                    'responsible_overridden' => $overridden[$definition->role_id],
                    // Il primo step e attivo, gli altri attendono il proprio turno.
                    'status' => ($index === 0 ? ReleaseStepStatus::Active : ReleaseStepStatus::Blocked)->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($definition->fieldDefinitions as $fieldIndex => $field) {
                    $fields[] = [
                        'id' => (string) Str::uuid7(),
                        // Si riusa l'identificativo gia generato: senza, servirebbe
                        // rileggere gli step appena scritti per ritrovarli.
                        'release_step_id' => $stepId,
                        'position' => $fieldIndex + 1,
                        'label' => $field->label,
                        'type' => $field->type->value,
                        'is_required' => $field->is_required,
                        'help_text' => $field->help_text,
                        'value' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Due sole scritture di massa: mai un create() per riga dentro un ciclo.
            ReleaseStep::insert($steps);

            if ($fields !== []) {
                ReleaseStepField::insert($fields);
            }

            $payload = [
                'label' => $label,
                'template' => $template->name,
                'steps' => count($steps),
            ];

            /*
             * La chiave compare **solo** quando c'e qualcosa da raccontare: un avvio
             * senza sovrascritture deve produrre lo stesso payload di prima di
             * US-013, chiave assente compresa. Una chiave sempre presente e vuota
             * sarebbe rumore su ogni riga del registro, e il registro e in sola
             * aggiunta — non si ripulisce dopo.
             */
            if ($resolution['journal'] !== []) {
                $payload['overrides'] = $resolution['journal'];
            }

            ReleaseEvent::create([
                'release_id' => $release->id,
                'user_id' => $actor->id,
                'action' => ReleaseEventAction::ReleaseStarted,
                'payload' => $payload,
            ]);

            return $release;
        });
    }

    /**
     * Il processo del progetto, verificato utilizzabile.
     *
     * La regola non e riscritta qui: `isUsable()` e `unusableReason()` vivono su
     * `WorkflowTemplate` da US-003, dichiarando gia allora che sarebbero servite
     * a questo.
     *
     * @throws InactiveProjectCannotStartRelease
     * @throws ProjectWithoutUsableTemplate
     */
    private function usableTemplate(Project $project): WorkflowTemplate
    {
        if (! $project->is_active) {
            throw InactiveProjectCannotStartRelease::for($project);
        }

        $template = $project->workflowTemplate;

        if ($template === null) {
            throw ProjectWithoutUsableTemplate::missing($project);
        }

        if (! $template->isUsable()) {
            throw ProjectWithoutUsableTemplate::unusable($project, (string) $template->unusableReason());
        }

        return $template;
    }

    /**
     * Persona responsabile per ciascun ruolo previsto dal processo, con accanto
     * quali ruoli sono stati sovrascritti in avvio e cosa annotarne nel registro.
     *
     * L'ordine dei controlli non e casuale, ed e la parte che vale la pena
     * leggere. Prima i ruoli non previsti dal processo e le persone non
     * ammissibili, **poi** la copertura: un override invalido che lasciasse anche
     * un ruolo scoperto deve riportare la causa vera — la scelta appena fatta — e
     * non quella derivata, che sparirebbe da sola correggendo la prima.
     *
     * Poi restano i due rifiuti che US-013 non tocca, nello stesso ordine di
     * prima: ruolo senza alcuna assegnazione e responsabile predefinito
     * disattivato sono due problemi diversi con due soluzioni diverse, e un
     * messaggio unico costringerebbe chi avvia a indovinare quale dei due sta
     * bloccando. Entrambi valgono solo sui ruoli **non** sovrascritti: coprire
     * qui un ruolo scoperto e il caso d'uso della spec, non un'eccezione a essa.
     *
     * Nessuna query per ruolo ne per step: si lavora sulle relazioni gia
     * precaricate dalla rilettura del progetto, piu **una sola** `whereIn` per le
     * persone degli override — che non viene nemmeno emessa quando non ce ne sono.
     *
     * @param  array<string, mixed>  $responsibleOverrides  ruolo => persona indicata in avvio
     * @return array{
     *     byRole: array<string, User>,
     *     overriddenRoles: array<string, bool>,
     *     journal: list<array{role: string, from: string|null, to: string}>,
     * }
     *
     * @throws ResponsibleOverrideRefused
     * @throws RolesWithoutResponsible
     * @throws InactiveResponsibleOnProject
     */
    private function responsibles(Project $project, WorkflowTemplate $template, array $responsibleOverrides): array
    {
        $neededRoles = $template->stepDefinitions->pluck('role_id')->unique()->values();

        $roleNames = $template->stepDefinitions
            ->mapWithKeys(fn ($definition): array => [$definition->role_id => $definition->role->name])
            ->all();

        /*
         * Una stringa vuota e "nessuna scelta", non una persona: il modulo la manda
         * per i ruoli lasciati sul predefinito e per quelli ancora da compilare.
         *
         * Un valore **non scalare** non e nemmeno una scelta, e cade prima del cast:
         * `(string) []` non produce un rifiuto ma un `ErrorException`, cioe un 500
         * al posto di un messaggio. Scartarlo e sicuro perche fallisce chiuso — il
         * ruolo torna al predefinito, e se non ne ha uno la copertura lo rifiuta
         * nominandolo. La schermata applica lo stesso filtro prima di validare.
         */
        $overrides = collect($responsibleOverrides)
            ->reject(fn (mixed $userId): bool => ! is_scalar($userId))
            ->map(fn (mixed $userId): string => (string) $userId)
            ->reject(fn (string $userId): bool => $userId === '');

        $this->refuseRolesOutsideProcess($project, $overrides, $neededRoles->all());

        $chosen = $this->assignableChoices($project, $overrides, $roleNames);

        $byRole = $project->assignments->keyBy('role_id');

        /** @var Collection<string, User|null> $defaults */
        $defaults = $neededRoles->mapWithKeys(fn (string $roleId): array => [
            $roleId => $byRole->get($roleId)?->user,
        ]);

        $uncovered = $project->uncoveredRoles()
            ->reject(fn (Role $role): bool => $overrides->has($role->id))
            ->values();

        if ($uncovered->isNotEmpty()) {
            throw RolesWithoutResponsible::on($project, $uncovered);
        }

        $inactive = $defaults
            ->reject(fn (?User $user, string $roleId): bool => $overrides->has($roleId))
            ->filter()
            ->reject(fn (User $user): bool => $user->is_active)
            ->unique('id')
            ->values();

        if ($inactive->isNotEmpty()) {
            throw InactiveResponsibleOnProject::on($project, $inactive);
        }

        /** @var Collection<string, User> $resolved */
        $resolved = $neededRoles->mapWithKeys(fn (string $roleId): array => [
            $roleId => $overrides->has($roleId)
                ? $chosen[$overrides->get($roleId)]
                : $defaults->get($roleId),
        ]);

        /*
         * Il confronto e per identificativo di persona e non per presenza della
         * chiave: indicare esplicitamente la stessa persona del predefinito non e
         * una sovrascrittura, e segnalarla come tale direbbe al dettaglio della
         * release una cosa falsa. Un ruolo prima scoperto invece **lo e**: non
         * c'era alcun predefinito da cui ereditare.
         */
        $overriddenRoles = $neededRoles->mapWithKeys(fn (string $roleId): array => [
            $roleId => $resolved->get($roleId)->id !== $defaults->get($roleId)?->id,
        ]);

        $journal = $neededRoles
            ->filter(fn (string $roleId): bool => $overriddenRoles->get($roleId))
            ->map(fn (string $roleId): array => [
                'role' => $roleNames[$roleId],
                // `null` e il ruolo che era scoperto: nel registro merita una frase
                // propria, non un nome mancante da interpretare.
                'from' => $defaults->get($roleId)?->name,
                'to' => $resolved->get($roleId)->name,
            ])
            ->values()
            ->all();

        return [
            'byRole' => $resolved->all(),
            'overriddenRoles' => $overriddenRoles->all(),
            'journal' => $journal,
        ];
    }

    /**
     * Rifiuta un payload che nomina ruoli fuori dal processo del progetto.
     *
     * La schermata filtra gia le chiavi ammesse, quindi qui non arriva nulla dal
     * modulo: arriva da un client che ha costruito la richiesta a mano. Il
     * controllo esiste perche l'autorizzazione degli override e una regola di
     * dominio, e le regole di dominio non si applicano nel form.
     *
     * @param  Collection<string, non-empty-string>  $overrides  le stringhe vuote sono gia cadute: una chiave presente significa "scelta fatta"
     * @param  list<string>  $neededRoles
     *
     * @throws ResponsibleOverrideRefused
     */
    private function refuseRolesOutsideProcess(Project $project, Collection $overrides, array $neededRoles): void
    {
        $extraneous = array_values(array_diff($overrides->keys()->all(), $neededRoles));

        if ($extraneous !== []) {
            throw ResponsibleOverrideRefused::roleNotInProcess($project, $extraneous);
        }
    }

    /**
     * Persone indicate in avvio, verificate esistenti e attive, indicizzate per
     * identificativo.
     *
     * Una sola query sui valori **distinti**: la stessa persona indicata su tre
     * ruoli non deve costarne tre, e nessun override non deve costarne nessuna.
     *
     * @param  Collection<string, non-empty-string>  $overrides  le stringhe vuote sono gia cadute: una chiave presente significa "scelta fatta"
     * @param  array<string, string>  $roleNames
     * @return Collection<string, User>
     *
     * @throws ResponsibleOverrideRefused
     */
    private function assignableChoices(Project $project, Collection $overrides, array $roleNames): Collection
    {
        if ($overrides->isEmpty()) {
            return collect();
        }

        $chosen = User::query()
            ->whereIn('id', $overrides->values()->unique()->all())
            ->get()
            ->keyBy('id');

        $reasons = $overrides
            ->mapWithKeys(function (string $userId, string $roleId) use ($chosen): array {
                $user = $chosen->get($userId);

                return match (true) {
                    $user === null => [$roleId => 'releases.override_refused_missing_member'],
                    ! $user->is_active => [$roleId => 'releases.override_refused_inactive_member'],
                    default => [],
                };
            })
            ->all();

        if ($reasons !== []) {
            throw ResponsibleOverrideRefused::notAssignable($project, $reasons, $roleNames);
        }

        return $chosen;
    }
}
