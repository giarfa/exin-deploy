<?php

namespace App\Exceptions;

use App\Models\ReleaseStep;
use RuntimeException;

/**
 * Un'altra transazione e passata per prima: questo invio non produce un secondo
 * avanzamento.
 *
 * E il rifiuto del **doppio invio**, non un errore di chi compila: l'update
 * condizionato non ha trovato piu la riga nello stato che si aspettava, e la
 * transazione viene annullata senza scrivere valori ne eventi. Il messaggio lo dice
 * come un fatto — l'avanzamento e avvenuto una sola volta, ed e quello che si
 * voleva.
 *
 * I due casi restano distinti perche descrivono due punti diversi della stessa
 * catena: `during()` e lo step chiuso da un altro invio mentre questo era in corso;
 * `whileConcludingRelease()` e la release conclusa da un altro invio dell'**ultimo**
 * step. Chi legge il messaggio dopo aver premuto due volte deve capire che cosa e
 * gia successo, e "il rilascio e stato consegnato" non e la stessa notizia di "il
 * passaggio e gia stato chiuso".
 *
 * Distinta da `StepIsNotOpen::stepIsCompleted()`, che descrive uno step trovato
 * gia chiuso all'inizio del tentativo: qui la chiusura e avvenuta **durante**.
 */
class StepAlreadyClosed extends RuntimeException
{
    /**
     * La chiave di traduzione viaggia con l'eccezione: il messaggio reso dalla
     * schermata descrive **quel** rifiuto, e non lo stato ricalcolato un istante
     * dopo. Stessa scelta di `StepIsNotOpen`.
     */
    private function __construct(string $message, public readonly string $reasonKey)
    {
        parent::__construct($message);
    }

    /**
     * Un altro invio ha chiuso lo step — o fatto avanzare la catena — per primo.
     */
    public static function during(ReleaseStep $step): self
    {
        return new self(
            "Lo step [{$step->name}] e stato chiuso da un altro invio mentre questo era in corso.",
            'releases.closing_already_closed'
        );
    }

    /**
     * Un altro invio dell'ultimo step ha gia concluso la release.
     */
    public static function whileConcludingRelease(ReleaseStep $step): self
    {
        return new self(
            "La release dello step [{$step->name}] e stata conclusa da un altro invio mentre questo era in corso.",
            'releases.closing_already_concluded'
        );
    }
}
