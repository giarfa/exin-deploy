<?php

namespace App\Enums;

/**
 * Stato di una release: in corso oppure conclusa.
 *
 * Due soli casi, e non e una semplificazione temporanea: annullare una release
 * (FR-020) e un requisito **Should** rinviato dal PRD, e introdurne subito il caso
 * significherebbe scriverlo in colonna su righe che nessun percorso applicativo
 * puo produrre. Quando FR-020 entrera in perimetro, il caso si aggiunge qui.
 *
 * Lo stato della release e distinto da quello dei suoi step
 * (`App\Enums\ReleaseStepStatus`): la release e conclusa quando l'ultimo step lo
 * e, ma sono due vocabolari con due significati.
 */
enum ReleaseStatus: string
{
    case InProgress = 'in_corso';
    case Completed = 'conclusa';

    /**
     * Etichetta leggibile per l'interfaccia.
     */
    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In corso',
            self::Completed => 'Conclusa',
        };
    }
}
