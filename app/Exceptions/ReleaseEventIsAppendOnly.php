<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Una riga del registro delle transizioni non si modifica e non si cancella.
 *
 * E la garanzia su cui poggia FR-016: un registro correggibile a posteriori non e
 * una prova di cosa e successo, e la ricostruzione di un rilascio contestato
 * varrebbe quanto il ricordo di chi lo racconta.
 *
 * **Portata esatta della garanzia** — vale per ogni scrittura che passa da un
 * modello: `$event->update()`, `$event->delete()`, `$event->save()` su una riga
 * gia esistente, incluse quelle fatte da tinker. **Non** vale per le scritture di
 * massa del query builder (`ReleaseEvent::query()->update()`,
 * `DB::table('release_events')->delete()`, `upsert()`), che per costruzione non
 * passano dagli eventi Eloquent, ne per la cancellazione a cascata quando sparisce
 * la release a cui l'evento si riferisce.
 *
 * Quelle strade restano aperte per una ragione dichiarata: chiuderle richiederebbe
 * un trigger di database, e il vincolo 1 del README impone migrazioni portabili
 * fra SQLite, MySQL e PostgreSQL. La difesa contro la cancellazione e altrove e
 * non qui — `ReleasePolicy::delete()` nega la cancellazione di una release a
 * chiunque, amministratori inclusi, e nessun percorso applicativo cancella eventi.
 * Chi introdurra il primo dovra passare da questa eccezione, non aggirarla.
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
