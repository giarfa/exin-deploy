<?php

namespace App\Enums;

/**
 * Vocabolario degli eventi registrati nel registro delle transizioni (FR-016).
 *
 * Nasce **completo** anche se questa spec ne scrive uno solo, e non e una
 * previsione: questi valori finiscono in colonna e sopravvivono nello storico,
 * quindi rinominarli piu avanti — quando esisteranno gia righe scritte —
 * costringerebbe a una migrazione di dati del tutto evitabile.
 *
 * Chi scrive cosa: `ReleaseStarted` e di US-004; `StepCompleted` e
 * `StepActivated` di US-005; `ReleaseCompleted` di US-006; `UnauthorizedAttempt`
 * di US-010. La consultazione del registro appartiene a US-010.
 */
enum ReleaseEventAction: string
{
    case ReleaseStarted = 'release_avviata';
    case StepCompleted = 'step_completato';
    case StepActivated = 'step_attivato';
    case ReleaseCompleted = 'release_conclusa';
    case UnauthorizedAttempt = 'tentativo_non_autorizzato';

    /**
     * Etichetta leggibile per l'interfaccia.
     */
    public function label(): string
    {
        return match ($this) {
            self::ReleaseStarted => 'Release avviata',
            self::StepCompleted => 'Step completato',
            self::StepActivated => 'Step attivato',
            self::ReleaseCompleted => 'Release conclusa',
            self::UnauthorizedAttempt => 'Tentativo non autorizzato',
        };
    }
}
