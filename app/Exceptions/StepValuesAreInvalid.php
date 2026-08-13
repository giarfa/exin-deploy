<?php

namespace App\Exceptions;

use Illuminate\Support\MessageBag;
use RuntimeException;

/**
 * I valori forniti non soddisfano cio che lo step chiede, e la chiusura e
 * rifiutata.
 *
 * L'eccezione porta con se il **`MessageBag`** prodotto dalla validazione, e non
 * un messaggio riassuntivo: la schermata deve poter collegare ogni errore al campo
 * che lo ha prodotto (`aria-describedby`, riepilogo con i collegamenti), e da un
 * testo unico quel legame non si ricostruisce.
 *
 * Rivalidare nella schermata sarebbe l'alternativa, ed e peggiore: due
 * validazioni sullo stesso valore sono due regole destinate a divergere, e la
 * seconda leggerebbe uno stato di un istante dopo.
 *
 * Le chiavi del bag sono gli **identificativi dei campi**, gli stessi con cui
 * `ReleaseStep::closingRules()` indicizza le regole.
 */
class StepValuesAreInvalid extends RuntimeException
{
    private function __construct(public readonly MessageBag $errors)
    {
        parent::__construct('I valori forniti per chiudere lo step non sono validi: '.implode(' ', $errors->all()));
    }

    public static function with(MessageBag $errors): self
    {
        return new self($errors);
    }
}
