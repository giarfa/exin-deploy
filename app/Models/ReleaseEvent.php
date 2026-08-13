<?php

namespace App\Models;

use App\Enums\ReleaseEventAction;
use App\Exceptions\ReleaseEventIsAppendOnly;
use Database\Factories\ReleaseEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riga del registro delle transizioni di una release: chi ha fatto cosa, e quando.
 *
 * Registro **in sola aggiunta** (FR-016). Una riga scritta non si modifica e non
 * si cancella: e la garanzia che rende il registro una prova e non un racconto.
 * Il rifiuto e applicato dal modello (`booted()`) e dichiarato dallo schema, che
 * non ha nemmeno la colonna `updated_at`.
 *
 * La portata esatta della garanzia — cosa e chiuso e cosa resta aperto, e perche —
 * e scritta su `App\Exceptions\ReleaseEventIsAppendOnly`. In breve: vale per ogni
 * scrittura che passa da un modello, non per le scritture di massa del query
 * builder, che non attraversano gli eventi Eloquent.
 *
 * Questa spec vi scrive un solo tipo di evento — l'avvio della release. Il
 * vocabolario completo vive in `App\Enums\ReleaseEventAction`; la consultazione
 * del registro appartiene a US-010.
 *
 * @property ReleaseEventAction $action
 * @property array<string, mixed>|null $payload
 * @property string $release_id
 */
#[Fillable(['release_id', 'release_step_id', 'user_id', 'action', 'payload'])]
class ReleaseEvent extends Model
{
    /** @use HasFactory<ReleaseEventFactory> */
    use HasFactory, HasUuids;

    /**
     * Il registro non ha una data di modifica perche una riga non si modifica.
     */
    public const UPDATED_AT = null;

    /**
     * Rifiuta modifica e cancellazione su ogni percorso che passa da un modello.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw ReleaseEventIsAppendOnly::onUpdate();
        });

        static::deleting(function (): never {
            throw ReleaseEventIsAppendOnly::onDelete();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ReleaseEventAction::class,
            'payload' => 'array',
        ];
    }

    /**
     * Release a cui l'evento si riferisce.
     *
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * Step interessato dall'evento; `null` per l'avvio, che riguarda la release nel
     * suo insieme e precede ogni step.
     *
     * La conclusione invece **porta** lo step finale: e da li che la consegna e
     * avvenuta, e leggere il registro senza quel riferimento lascerebbe la riga piu
     * importante senza il passaggio che l'ha prodotta.
     *
     * @return BelongsTo<ReleaseStep, $this>
     */
    public function releaseStep(): BelongsTo
    {
        return $this->belongsTo(ReleaseStep::class);
    }

    /**
     * Attore dell'evento.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
