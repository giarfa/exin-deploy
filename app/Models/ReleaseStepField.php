<?php

namespace App\Models;

use App\Enums\FieldType;
use App\Rules\WellFormedLink;
use Database\Factories\ReleaseStepFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Informazione richiesta da uno step di una release avviata, e il valore fornito
 * chiudendolo.
 *
 * Etichetta, forma, obbligatorieta e testo di aiuto sono la copia congelata della
 * definizione: cambiarla dopo l'avvio non cambia cosa questa release chiede.
 *
 * `value` e una sola colonna testuale per tutti e quattro i tipi. La semantica
 * per tipo — un link deve essere un indirizzo valido, una conferma obbligatoria
 * deve risultare spuntata — vive qui, in `closingRules()` e `normalizeValue()`:
 * la regola sta dove sta il dato congelato, in **una sola copia** usata sia
 * dall'Action di chiusura sia dalla schermata. `App\Enums\FieldType` dichiara nel
 * proprio PHPDoc che questa semantica non gli appartiene, perche l'enum descrive
 * la forma del campo e non cosa rende accettabile un valore.
 *
 * **Nessun uso di `OrderedByPosition`**: come per `ReleaseStep`, lo snapshot non
 * si riordina e un percorso di rinumerazione sarebbe una porta aperta sull'ordine
 * congelato.
 *
 * @property int $position
 * @property bool $is_required
 * @property FieldType $type
 * @property string $release_step_id
 */
#[Fillable([
    'release_step_id',
    'position',
    'label',
    'type',
    'is_required',
    'help_text',
    'value',
])]
class ReleaseStepField extends Model
{
    /** @use HasFactory<ReleaseStepFieldFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'type' => FieldType::class,
            'is_required' => 'boolean',
        ];
    }

    /**
     * Step che richiede questo campo.
     *
     * @return BelongsTo<ReleaseStep, $this>
     */
    public function releaseStep(): BelongsTo
    {
        return $this->belongsTo(ReleaseStep::class);
    }

    /**
     * Regole che il valore fornito deve soddisfare perche lo step si chiuda.
     *
     * `bail` apre ogni elenco, e non e cosmetico: senza, un campo obbligatorio
     * lasciato vuoto fallirebbe `required` **e** `string` — due messaggi diversi
     * per lo stesso difetto, sullo stesso campo, dentro il riepilogo errori che il
     * mockup vuole leggibile a colpo d'occhio.
     *
     * La conferma obbligatoria usa `accepted` accanto a `required`: `required`
     * intercetta l'assenza, `accepted` il valore presente ma non affermativo — che
     * l'interfaccia non produce, ma una chiamata diretta si.
     *
     * @return list<mixed>
     */
    public function closingRules(): array
    {
        $presence = $this->is_required ? 'required' : 'nullable';

        return match ($this->type) {
            FieldType::ShortText => ['bail', $presence, 'string', 'max:255'],
            FieldType::LongText => ['bail', $presence, 'string', 'max:5000'],
            FieldType::Link => ['bail', $presence, 'string', 'max:2048', new WellFormedLink],
            FieldType::Confirmation => $this->is_required
                ? ['bail', 'required', 'accepted']
                : ['bail', $presence, 'boolean'],
        };
    }

    /**
     * Valore da scrivere in colonna a partire da quanto fornito.
     *
     * Un campo lasciato vuoto diventa **`null`** e non stringa vuota: il dettaglio
     * della release (US-008) deve poter dire "non fornito", e una colonna che
     * contiene `''` non lo consente piu — `''` e un valore fornito che si dava il
     * caso fosse vuoto, e i due casi non si distinguono piu a posteriori.
     *
     * La conferma spuntata diventa `'1'`; quella non spuntata diventa `null` e non
     * `'0'`, per la stessa ragione: "non ho confermato" e "non ho compilato" sono
     * lo stesso fatto su un campo che ha una sola direzione.
     *
     * La normalizzazione precede sempre la validazione, e non e invertibile:
     * `required` accetterebbe `false` — che non e vuoto per Laravel — mentre qui
     * `false` e proprio l'assenza di conferma.
     */
    public function normalizeValue(mixed $value): ?string
    {
        if ($this->type === FieldType::Confirmation) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : null;
        }

        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Campi nell'ordine in cui vanno compilati.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position');
    }
}
