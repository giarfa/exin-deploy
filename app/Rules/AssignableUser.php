<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La persona indicata deve esistere ed essere ancora attiva.
 *
 * Regola condivisa dalla mappatura di progetto e da quella predefinita: assegnare
 * un ruolo a un membro disattivato lascerebbe uno step di rilascio in carico a chi
 * non puo piu accedere, e il flusso si fermerebbe in silenzio.
 *
 * Il messaggio distingue i due casi (inesistente / disattivato) perche all'utente
 * servono azioni diverse: correggere la scelta oppure riattivare il membro.
 */
class AssignableUser implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = User::query()
            ->select(['id', 'name', 'is_active'])
            ->find($value);

        if (! $user) {
            $fail('validation.assignable_user.missing')->translate();

            return;
        }

        if (! $user->is_active) {
            $fail('validation.assignable_user.inactive')->translate(['name' => $user->name]);
        }
    }
}
