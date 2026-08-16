<?php

namespace App\Models;

use Database\Factories\ProjectRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona che ricopre un ruolo su un singolo progetto.
 *
 * E questa mappatura che trasforma un template astratto in un processo con
 * responsabili reali: all'avvio di una release il ruolo di ogni step viene
 * risolto in una persona leggendo queste righe.
 */
#[Fillable(['project_id', 'role_id', 'user_id'])]
class ProjectRoleAssignment extends Model
{
    /** @use HasFactory<ProjectRoleAssignmentFactory> */
    use HasFactory, HasUuids;

    /**
     * Progetto su cui vale la mappatura.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Ruolo funzionale mappato.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Persona che ricopre il ruolo sul progetto.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
