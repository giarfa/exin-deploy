<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ordinamento per posizione dentro una sequenza, con la garanzia che le posizioni
 * restino **contigue e senza duplicati** dopo ogni operazione.
 *
 * Usato dagli step di un template e dai campi di uno step: la regola vive qui una
 * volta sola, cosi che la contiguita non sia una responsabilita di chi chiama.
 *
 * L'unicita della coppia (padre, posizione) e applicata dallo schema, e questo
 * vincola il modo in cui si riordina: durante uno scambio o una rinumerazione
 * esiste un istante in cui due righe vorrebbero la stessa posizione. Per questo
 * ogni scrittura passa da un valore **temporaneo negativo** prima di assumere
 * quello definitivo, ed e il motivo per cui la colonna `position` e un `integer`
 * con segno e non un `unsignedInteger`.
 *
 * @phpstan-require-extends Model
 *
 * @property int $position
 */
trait OrderedByPosition
{
    /**
     * I fratelli nella stessa sequenza, questa riga inclusa.
     *
     * Il modello che usa il concern dichiara cosa significa "stessa sequenza":
     * gli step di uno stesso template, i campi di uno stesso step.
     *
     * @return Builder<static>
     */
    abstract public function sequence(): Builder;

    /**
     * Posizione da assegnare a una nuova riga, in coda alla sequenza.
     */
    public function nextPosition(): int
    {
        return (int) $this->sequence()->max('position') + 1;
    }

    /**
     * Sposta la riga di una posizione verso l'alto. Sul primo elemento non fa nulla.
     */
    public function moveUp(): void
    {
        $this->swapWith(
            $this->sequence()
                ->where('position', '<', $this->position)
                ->orderByDesc('position')
                ->first()
        );
    }

    /**
     * Sposta la riga di una posizione verso il basso. Sull'ultimo elemento non fa nulla.
     */
    public function moveDown(): void
    {
        $this->swapWith(
            $this->sequence()
                ->where('position', '>', $this->position)
                ->orderBy('position')
                ->first()
        );
    }

    /**
     * Cancella la riga e rinumera la sequenza rimasta.
     *
     * Ogni cancellazione passa da qui: una posizione lasciata vuota renderebbe
     * illeggibile l'ordine del processo configurato.
     */
    public function deleteAndResequence(): void
    {
        DB::transaction(function (): void {
            $this->delete();
            $this->resequence();
        });
    }

    /**
     * Riporta la sequenza a posizioni `1..N`, rispettando l'ordine corrente.
     *
     * Due passaggi dentro una sola transazione: prima ogni riga assume la propria
     * posizione finale **negata**, poi il valore assoluto. Senza il passaggio
     * intermedio la seconda riga scriverebbe una posizione ancora occupata dalla
     * prima, e l'indice unico rifiuterebbe la scrittura.
     */
    protected function resequence(): void
    {
        DB::transaction(function (): void {
            $ordered = $this->sequence()->orderBy('position')->get();

            foreach ($ordered as $index => $row) {
                $this->sequence()->whereKey($row->getKey())->update(['position' => -($index + 1)]);
            }

            foreach ($ordered as $index => $row) {
                $this->sequence()->whereKey($row->getKey())->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * Scambia la posizione con il vicino indicato, se esiste.
     *
     * Il passaggio dalla posizione negativa vale anche qui: senza, lo scambio
     * violerebbe l'indice unico a meta strada.
     *
     * @param  static|null  $neighbour
     */
    private function swapWith($neighbour): void
    {
        if ($neighbour === null) {
            return;
        }

        DB::transaction(function () use ($neighbour): void {
            $mine = $this->position;
            $theirs = $neighbour->position;

            $this->sequence()->whereKey($this->getKey())->update(['position' => -$mine]);
            $this->sequence()->whereKey($neighbour->getKey())->update(['position' => $mine]);
            $this->sequence()->whereKey($this->getKey())->update(['position' => $theirs]);

            $this->position = $theirs;
            $neighbour->position = $mine;
        });
    }
}
