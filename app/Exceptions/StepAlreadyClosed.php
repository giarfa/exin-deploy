<?php

namespace App\Exceptions;

use App\Models\ReleaseStep;
use RuntimeException;

/**
 * Un'altra transazione ha chiuso lo step per prima: questo invio non produce un
 * secondo avanzamento.
 *
 * E il rifiuto del **doppio invio**, non un errore di chi compila: l'update
 * condizionato allo stato `attivo` non ha trovato piu la riga in quello stato, e la
 * transazione viene annullata senza scrivere valori ne eventi. Il messaggio lo dice
 * come un fatto — l'avanzamento e avvenuto una sola volta, ed e quello che si
 * voleva.
 *
 * Distinta da `StepIsNotOpen::stepIsCompleted()`, che descrive uno step trovato
 * gia chiuso all'inizio del tentativo: qui la chiusura e avvenuta **durante**, e
 * la differenza conta per chi legge il messaggio dopo aver premuto due volte.
 */
class StepAlreadyClosed extends RuntimeException
{
    public static function during(ReleaseStep $step): self
    {
        return new self(
            "Lo step [{$step->name}] e stato chiuso da un altro invio mentre questo era in corso."
        );
    }
}
