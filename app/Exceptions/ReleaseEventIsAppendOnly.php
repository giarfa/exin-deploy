<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Una riga del registro delle transizioni non si modifica e non si cancella.
 *
 * E la garanzia strutturale su cui poggia FR-016: un registro correggibile a
 * posteriori non e una prova di cosa e successo, e la ricostruzione di un
 * rilascio contestato varrebbe quanto il ricordo di chi lo racconta.
 *
 * Il rifiuto vive nel modello e non nella sola disciplina di chi scrive il
 * codice: cosi vale anche per un `update()` di massa o per una correzione fatta
 * da tinker.
 */
class ReleaseEventIsAppendOnly extends RuntimeException
{
    public static function onUpdate(): self
    {
        return new self(
            'Il registro delle transizioni e in sola aggiunta: una riga gia scritta non si modifica.'
        );
    }

    public static function onDelete(): self
    {
        return new self(
            'Il registro delle transizioni e in sola aggiunta: una riga gia scritta non si cancella.'
        );
    }
}
