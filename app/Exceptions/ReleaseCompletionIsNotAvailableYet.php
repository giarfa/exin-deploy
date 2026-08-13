<?php

namespace App\Exceptions;

use App\Models\ReleaseStep;
use RuntimeException;

/**
 * L'ultimo step della catena non si chiude ancora: concludere la release e US-006
 * (FR-017), e questa spec si ferma sul confine.
 *
 * **Perche rifiutare e non chiudere lo stesso.** Chiudere l'ultimo step senza
 * concludere la release lascerebbe una release `in_corso` senza alcuno step
 * attivo: nessuno saprebbe di chi e il turno, la vista "i miei step" non la
 * mostrerebbe a nessuno, e sarebbe proprio la violazione dell'invariante che
 * questa spec esiste per dimostrare — al massimo uno step attivo per release,
 * **zero** solo quando la release e conclusa.
 *
 * Rifiutare invece lascia uno stato coerente e leggibile: l'ultimo step resta
 * attivo e in carico al suo responsabile, e chiuderlo diventa possibile quando la
 * conclusione esiste.
 *
 * US-006 sostituisce il `throw` con la conclusione della release: la scrittura di
 * `completed_by` e `completed_at` su `releases`, il passaggio a `conclusa` e
 * l'evento `release_conclusa`. Il ramo terminale di `CloseStep` e il punto in cui
 * quel codice entra — non serve altro.
 */
class ReleaseCompletionIsNotAvailableYet extends RuntimeException
{
    public static function on(ReleaseStep $step): self
    {
        return new self(
            "Lo step [{$step->name}] e l'ultimo della catena: la conclusione della release non e ancora disponibile."
        );
    }
}
