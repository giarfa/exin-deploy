<?php

namespace App\Enums;

/**
 * Forma di un campo informativo richiesto da uno step del processo.
 *
 * I quattro casi sono il vocabolario completo previsto dal prodotto: chi chiude
 * uno step fornisce un testo breve, un testo lungo, un collegamento oppure una
 * conferma. Un valore fuori da questo elenco e rifiutato in scrittura
 * (`Rule::enum`) e solleva in lettura (cast Eloquent).
 *
 * Qui l'enum descrive soltanto **la forma** del campo. La semantica di
 * validazione del valore fornito — un link deve essere un indirizzo valido, una
 * conferma obbligatoria deve risultare spuntata — appartiene alla chiusura dello
 * step (US-005/US-006) e non a questo enum.
 */
enum FieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Link = 'link';
    case Confirmation = 'confirmation';

    /**
     * Etichetta leggibile per l'interfaccia.
     */
    public function label(): string
    {
        return match ($this) {
            self::ShortText => 'Testo breve',
            self::LongText => 'Testo lungo',
            self::Link => 'Link',
            self::Confirmation => 'Conferma',
        };
    }
}
