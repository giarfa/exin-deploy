<?php

namespace App\Actions\Releases;

use App\Enums\ReleaseEventAction;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Exceptions\InactiveProjectCannotStartRelease;
use App\Exceptions\InactiveResponsibleOnProject;
use App\Exceptions\ProjectWithoutUsableTemplate;
use App\Exceptions\RolesWithoutResponsible;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use App\Models\WorkflowTemplate;
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
 * forma, obbligatorieta e testo di aiuto dei campi. Cosa resta riferimento vivo:
 * l'**identita** di persone e progetto — se una persona cambia cognome lo storico
 * deve mostrare la persona, non un nome fossile.
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
     * @throws InactiveProjectCannotStartRelease se il progetto e disattivato
     * @throws ProjectWithoutUsableTemplate se il processo manca, e disattivato o non ha step
     * @throws RolesWithoutResponsible se un ruolo previsto non ha un responsabile sul progetto
     * @throws InactiveResponsibleOnProject se un responsabile risolto e disattivato
     */
    public function handle(Project $project, string $label, User $actor): Release
    {
        return DB::transaction(function () use ($project, $label, $actor): Release {
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
            $responsibles = $this->responsibles($project, $template);

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

            ReleaseEvent::create([
                'release_id' => $release->id,
                'user_id' => $actor->id,
                'action' => ReleaseEventAction::ReleaseStarted,
                'payload' => [
                    'label' => $label,
                    'template' => $template->name,
                    'steps' => count($steps),
                ],
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
     * Persona responsabile per ciascun ruolo previsto dal processo, indicizzata
     * per ruolo.
     *
     * Rifiuta prima i ruoli scoperti e poi i responsabili disattivati: sono due
     * problemi diversi con due soluzioni diverse, e un messaggio unico
     * costringerebbe chi avvia a indovinare quale dei due sta bloccando.
     *
     * @return array<string, User>
     *
     * @throws RolesWithoutResponsible
     * @throws InactiveResponsibleOnProject
     */
    private function responsibles(Project $project, WorkflowTemplate $template): array
    {
        $uncovered = $project->uncoveredRoles();

        if ($uncovered->isNotEmpty()) {
            throw RolesWithoutResponsible::on($project, $uncovered);
        }

        $byRole = $project->assignments->keyBy('role_id');

        $needed = $template->stepDefinitions
            ->pluck('role_id')
            ->unique()
            ->mapWithKeys(fn (string $roleId): array => [
                $roleId => $byRole->get($roleId)->user,
            ]);

        $inactive = $needed->reject(fn (User $user): bool => $user->is_active)->unique('id')->values();

        if ($inactive->isNotEmpty()) {
            throw InactiveResponsibleOnProject::on($project, $inactive);
        }

        return $needed->all();
    }
}
