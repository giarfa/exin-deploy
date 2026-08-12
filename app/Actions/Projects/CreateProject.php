<?php

namespace App\Actions\Projects;

use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea un progetto e vi precompila la mappatura predefinita di team.
 *
 * E il comportamento che elimina la riconfigurazione manuale a ogni nuovo
 * progetto. Deliberatamente una Action esplicita e non un observer sull'evento
 * `created` del modello: con un observer ogni `Project::factory()->create()` di
 * test o di seeder erediterebbe mappature a sorpresa, e la logica di dominio
 * sarebbe invisibile a chi legge il componente che crea il progetto.
 *
 * La copia avviene una sola volta, qui. Da questo momento le due mappature sono
 * indipendenti: modificare quella del progetto non tocca la predefinita, e
 * modificare la predefinita non tocca i progetti gia creati.
 */
class CreateProject
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{project: Project, skipped: int} `skipped` e il numero di ruoli
     *                                               non precompilati perche il ruolo o la persona predefinita
     *                                               risultano disattivati
     */
    public function handle(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $project = Project::create($attributes);

            /*
             * Si copiano solo le predefinite utilizzabili: un ruolo disattivato non
             * va proposto su un progetto nuovo, e una persona disattivata non puo
             * ricoprire un ruolo (stesso vincolo della regola AssignableUser).
             */
            $assignable = DefaultRoleAssignment::query()
                ->whereHas('role', fn ($query) => $query->where('is_active', true))
                ->whereHas('user', fn ($query) => $query->where('is_active', true))
                ->get(['role_id', 'user_id']);

            $now = now();

            $rows = $assignable
                ->map(fn (DefaultRoleAssignment $default): array => [
                    'id' => (string) Str::uuid7(),
                    'project_id' => $project->id,
                    'role_id' => $default->role_id,
                    'user_id' => $default->user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($rows !== []) {
                // Una sola scrittura: mai un create() per riga dentro un ciclo.
                ProjectRoleAssignment::insert($rows);
            }

            return [
                'project' => $project,
                'skipped' => DefaultRoleAssignment::count() - $assignable->count(),
            ];
        });
    }
}
