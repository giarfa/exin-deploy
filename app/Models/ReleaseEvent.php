<?php

namespace App\Models;

use App\Enums\ReleaseEventAction;
use App\Exceptions\ReleaseEventIsAppendOnly;
use Database\Factories\ReleaseEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * Il vocabolario degli eventi vive in `App\Enums\ReleaseEventAction`, oggi scritto
 * per intero: avvio, chiusura di uno step, attivazione del successivo, conclusione
 * della release e tentativo non autorizzato.
 *
 * La consultazione e in `/rilasci/{release}/registro`. Non tutte le voci sono per
 * tutti: i **tentativi non autorizzati** restano ai soli amministratori, e il filtro
 * vive nello scope `visibleTo()` piu sotto, allineato a `ReleaseEventPolicy::view()`.
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

    /**
     * Voci di una release, quelle che il suo registro mostra.
     *
     * Usa l'indice `(release_id, created_at)` creato con la tabella proprio per
     * questa lettura, e non un indice sul solo `release_id`: la cronologia si legge
     * sempre ordinata.
     */
    #[Scope]
    protected function forRelease(Builder $query, Release $release): void
    {
        $query->where('release_id', $release->getKey());
    }

    /**
     * Ordine cronologico: dal primo evento all'ultimo.
     *
     * **Crescente e non decrescente**, al contrario delle altre schermate. Quelle
     * rispondono a "cosa devo fare ora" e mettono in cima il piu recente; un
     * registro racconta come e andata, e una cronologia si legge dall'inizio —
     * l'avvio in testa, la consegna in fondo. E anche l'unico ordine in cui "step
     * attivato" ha senso subito dopo "step completato".
     *
     * Lo spareggio sull'identificativo non e cosmetico: `CloseStep` scrive due
     * eventi nella **stessa transazione**, quindi con lo stesso istante al secondo.
     * Ordinando sul solo `created_at` i due si scambierebbero di posto fra due
     * letture, e la cronologia direbbe che il flusso e passato al responsabile
     * successivo prima che lo step precedente si chiudesse. Gli UUIDv7 sono
     * monotoni, quindi l'ordine di scrittura e recuperabile dalla chiave.
     */
    #[Scope]
    protected function chronological(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }

    /**
     * Voci che una persona puo vedere.
     *
     * E la **stessa decisione** di `ReleaseEventPolicy::view()`, espressa in query:
     * i tentativi non autorizzati restano ai soli amministratori. Vive qui e non in
     * un filtro sulla collezione gia caricata perche caricarle per scartarle
     * significherebbe leggere righe che chi guarda non puo vedere — e perche il loro
     * **numero** e a sua volta informazione di sicurezza, che una collezione filtrata
     * a valle rischia di far trapelare in un conteggio.
     *
     * Le due formulazioni devono restare allineate: `ReleaseEventPolicyTest` le
     * confronta riga per riga sullo stesso insieme, cosi che aprirne una senza
     * l'altra faccia fallire la suite invece di aprire una fuga.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->unless(
            $user->isAdministrator(),
            fn (Builder $restricted) => $restricted->whereNot('action', ReleaseEventAction::UnauthorizedAttempt)
        );
    }
}
