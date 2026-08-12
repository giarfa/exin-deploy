<?php

namespace App\Enums;

/**
 * Livello applicativo di un membro del team.
 *
 * Non e il ruolo funzionale nel processo di rilascio (QA, DevOps, ...): quello
 * e un'entita configurabile a parte (US-002). Qui si decide soltanto chi puo
 * configurare il sistema e intervenire su qualsiasi release.
 */
enum UserLevel: string
{
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Etichetta leggibile per l'interfaccia.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Amministratore',
            self::Member => 'Membro',
        };
    }
}
