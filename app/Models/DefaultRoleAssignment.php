<?php

namespace App\Models;

use Database\Factories\DefaultRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona che ricopre un ruolo per impostazione predefinita a livello di team.
 *
 * E la sorgente da cui la mappatura di un nuovo progetto viene precompilata.
 * La copia avviene una sola volta, alla creazione del progetto: modificare
 * questa tabella non tocca i progetti gia esistenti, e modificare la mappatura
 * di un progetto non tocca questa.
 */
#[Fillable(['role_id', 'user_id'])]
class DefaultRoleAssignment extends Model
{
    /** @use HasFactory<DefaultRoleAssignmentFactory> */
    use HasFactory, HasUuids;

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
     * Persona che ricopre il ruolo per impostazione predefinita.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
