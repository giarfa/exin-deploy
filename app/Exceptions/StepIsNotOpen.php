<?php

namespace App\Exceptions;

use App\Models\ReleaseStep;
use RuntimeException;

/**
 * Non si compila ne si chiude uno step che non e aperto.
 *
 * I tre casi sono distinti e non riuniti in un messaggio unico — release conclusa,
 * step non ancora arrivato al proprio turno, step gia chiuso — perche descrivono
 * tre situazioni che chi le incontra risolve in tre modi diversi: la prima non si
 * risolve, la seconda si aspetta, la terza si legge. Stessa scelta fatta in US-004
 * per i rifiuti dell'avvio.
 *
 * La chiave di traduzione viaggia con l'eccezione: il messaggio reso dalla
 * schermata descrive **quel** tentativo, e non lo stato ricalcolato un istante
 * dopo, che potrebbe essere cambiato.
 */
class StepIsNotOpen extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonKey)
    {
        parent::__construct($message);
    }

    /**
     * La release e conclusa: non ha piu step su cui avanzare.
     */
    public static function releaseIsCompleted(ReleaseStep $step): self
    {
        return new self(
            "La release dello step [{$step->name}] e conclusa: non ci sono piu step da chiudere.",
            'releases.closing_blocked_release_completed'
        );
    }

    /**
     * Lo step attende il proprio turno: quelli che lo precedono non sono chiusi.
     */
    public static function stepIsBlocked(ReleaseStep $step): self
    {
        return new self(
            "Lo step [{$step->name}] e bloccato: il suo turno non e ancora arrivato.",
            'releases.closing_blocked_step_blocked'
        );
    }

    /**
     * Lo step e gia chiuso, e un passaggio chiuso non si riapre: la riapertura e
     * FR-019, rinviata oltre l'MVP dal PRD.
     */
    public static function stepIsCompleted(ReleaseStep $step): self
    {
        return new self(
            "Lo step [{$step->name}] e gia stato chiuso: i valori forniti sono in sola lettura.",
            'releases.closing_blocked_step_completed'
        );
    }
}
