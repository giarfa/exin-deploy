<?php

namespace App\Models;

use App\Enums\ReleaseStepStatus;
use Carbon\CarbonInterface;
use Database\Factories\ReleaseStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Passaggio di una release avviata: la copia congelata di uno step del template,
 * con in piu cio che la definizione non sa — chi ne risponde e a che punto e.
 *
 * Nome, istruzioni, posizione e nome del ruolo sono **copiati** all'avvio: da quel
 * momento modificare, riordinare o cancellare la definizione non tocca questa
 * riga. E il motivo per cui esiste una tabella separata invece di un riferimento.
 *
 * `role_name` e la fonte di verita per la lettura; `role_id` resta accanto solo
 * per rendere il ruolo non cancellabile. Rinominare un ruolo non deve riscrivere
 * lo storico dei rilasci gia eseguiti.
 *
 * **Nessun uso di `OrderedByPosition`**, ed e deliberato: quel trait serve a
 * rinumerare una sequenza modificabile, e lo snapshot non si riordina. Averlo
 * qui aprirebbe un percorso di scrittura verso l'ordine congelato, cioe verso
 * l'unica cosa che questa tabella esiste per proteggere.
 *
 * @property int $position
 * @property ReleaseStepStatus $status
 * @property CarbonInterface|null $completed_at
 * @property string $release_id
 * @property CarbonInterface|null $previous_step_completed_at colonna calcolata da `withActivationInstant()`, assente senza quello scope
 */
#[Fillable([
    'release_id',
    'position',
    'name',
    'instructions',
    'role_id',
    'role_name',
    'assigned_user_id',
    'status',
])]
class ReleaseStep extends Model
{
    /** @use HasFactory<ReleaseStepFactory> */
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
            'status' => ReleaseStepStatus::class,
            'completed_at' => 'datetime',
            /*
             * Non e una colonna: e l'alias della sottoquery di
             * `withActivationInstant()`. Il cast e comunque necessario, altrimenti
             * l'istante tornerebbe come stringa e `diffForHumans()` non esisterebbe
             * su di essa.
             */
            'previous_step_completed_at' => 'datetime',
        ];
    }

    /**
     * Release a cui lo step appartiene.
     *
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * Ruolo che era responsabile dello step al momento dell'avvio.
     *
     * Serve a rendere il ruolo non cancellabile e a risalire al catalogo; per
     * mostrare il ruolo si legge `role_name`, che e congelato.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Persona responsabile dello step, risolta all'avvio dalla mappatura del
     * progetto.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Persona che ha chiuso lo step; `null` finche non e completato.
     *
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Campi da fornire per chiudere lo step, sempre in ordine di compilazione.
     *
     * L'ordine e parte del significato della relazione e non una scelta di chi la
     * interroga, come per `StepDefinition::fieldDefinitions()`.
     *
     * @return HasMany<ReleaseStepField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(ReleaseStepField::class)->orderBy('position');
    }

    /**
     * Regole dell'intero form di chiusura, indicizzate per **identificativo** del
     * campo.
     *
     * L'identificativo e non la posizione ne l'etichetta: la posizione dice dove
     * sta il campo e non quale e, e due campi possono avere la stessa etichetta.
     * La chiave e la stessa con cui la schermata indicizza i valori compilati,
     * cosi che gli errori tornino sul campo che li ha prodotti.
     *
     * Legge la relazione e non le definizioni: chi la invoca deve averla caricata
     * in eager loading, altrimenti paga una query per campo (vedi
     * `CloseStepQueryBudgetTest`).
     *
     * @return array<string, list<mixed>>
     */
    public function closingRules(): array
    {
        return $this->fields
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => $field->closingRules(),
            ])
            ->all();
    }

    /**
     * Etichette congelate dei campi, come nomi leggibili dentro i messaggi di
     * rifiuto: senza, un errore parlerebbe di un UUID.
     *
     * @return array<string, string>
     */
    public function closingAttributes(): array
    {
        return $this->fields
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => $field->label,
            ])
            ->all();
    }

    /**
     * Step che riceve il flusso quando questo si chiude; `null` sull'ultimo della
     * catena.
     *
     * Legge **solo** lo snapshot: risalire a `step_definitions` per sapere cosa
     * viene dopo riporterebbe la definizione dentro l'esecuzione, e riordinare un
     * template cambierebbe l'ordine di una release gia avviata.
     *
     * La prima posizione maggiore, e non `position + 1`: le posizioni dello
     * snapshot nascono contigue e non si riordinano, ma leggerle in questo modo non
     * dipende da quella garanzia — un buco produrrebbe uno step orfano invece di
     * una catena interrotta.
     */
    public function nextStep(): ?ReleaseStep
    {
        return static::query()
            ->where('release_id', $this->release_id)
            ->where('position', '>', $this->position)
            ->ordered()
            ->first();
    }

    /**
     * Istante in cui lo step ha ricevuto il flusso, cioe da quando attende chi ne
     * risponde.
     *
     * **Derivato, non memorizzato.** Non esiste una colonna `activated_at`, e non
     * per dimenticanza: `App\Actions\Releases\CloseStep` chiude lo step precedente
     * e attiva questo **nella stessa transazione**, quindi il `completed_at` del
     * precedente e l'istante di attivazione di questo — coincidono per costruzione
     * e non per approssimazione. Sul primo della catena non c'e un precedente, e
     * l'istante e quello di avvio della release, che `StartRelease` scrive creando
     * gia attivo lo step in posizione 1.
     *
     * La scelta e registrata nel piano di US-007 ("Decisione — da quanto uno step
     * e aperto") insieme all'alternativa scartata: quando servira **ordinare o
     * filtrare in database** su questo istante, la colonna diventera giustificata.
     *
     * Richiede lo scope `withActivationInstant()` sulla query e la release
     * caricata. L'assenza dell'alias non torna un ripiego silenzioso ma
     * un'eccezione: una durata sbagliata e indistinguibile da una giusta a chi
     * guarda la schermata, e il blocco delle release in attesa esiste proprio per
     * dire da quanto qualcuno e fermo.
     *
     * @throws \LogicException quando la query non ha applicato `withActivationInstant()`
     */
    public function activationInstant(): CarbonInterface
    {
        if (! array_key_exists('previous_step_completed_at', $this->attributes)) {
            throw new \LogicException(
                'ReleaseStep::activationInstant() richiede lo scope withActivationInstant() sulla query.'
            );
        }

        return $this->previous_step_completed_at ?? $this->release->started_at;
    }

    /**
     * Step in ordine di esecuzione.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position');
    }

    /**
     * Step aperti in attesa di una persona: il contenuto di "i miei step".
     *
     * Il filtro e **sull'assegnazione**, non sulla Policy. `ReleaseStepPolicy`
     * concede a un amministratore la lettura di qualunque step, ma questa
     * schermata si chiama "i miei step": mostrargli anche quelli altrui la
     * trasformerebbe in un cruscotto di sorveglianza e seppellirebbe cio che
     * attende davvero lui.
     *
     * Le release concluse restano fuori due volte — non hanno step attivi per
     * invariante, e la condizione lo dice comunque: quell'invariante e mantenuta
     * dal codice, e una schermata che ci si appoggia in silenzio si romperebbe
     * senza spiegare perche il giorno in cui un dato incoerente entrasse.
     *
     * Usa l'indice `(assigned_user_id, status)` creato con la tabella.
     */
    #[Scope]
    protected function awaitingUser(Builder $query, User $user): void
    {
        $query->where('assigned_user_id', $user->id)
            ->where('status', ReleaseStepStatus::Active)
            // Sottoquery su `Release::inProgress()` e non una seconda copia della
            // condizione sullo stato: cosa significhi "in corso" e deciso in un
            // posto solo, e il giorno in cui FR-020 aggiungera "annullata" questa
            // schermata la seguira senza essere toccata.
            ->whereIn('release_id', Release::query()->inProgress()->select('id'));
    }

    /**
     * Aggiunge alla riga l'istante di chiusura dello step precedente, letto con una
     * sottoquery correlata: costo costante, nessuna query per riga.
     *
     * `select('release_steps.*')` non e ridondante: la sola `addSelect`
     * sostituirebbe la lista delle colonne con quell'unico alias, e la query
     * tornerebbe righe senza nome, stato ne ruolo.
     *
     * La tabella interna e **aliasata**: senza `previous_steps` i `whereColumn`
     * legherebbero entrambi i lati alla stessa tabella e la sottoquery
     * confronterebbe ogni riga con se stessa.
     *
     * Solo costrutti portabili: nessuna funzione specifica di SQLite, perche il
     * passaggio a PostgreSQL o MySQL deve restare un cambio di configurazione.
     */
    #[Scope]
    protected function withActivationInstant(Builder $query): void
    {
        $query->select('release_steps.*')->addSelect([
            'previous_step_completed_at' => DB::table('release_steps as previous_steps')
                ->select('previous_steps.completed_at')
                ->whereColumn('previous_steps.release_id', 'release_steps.release_id')
                ->whereColumn('previous_steps.position', '<', 'release_steps.position')
                ->orderByDesc('previous_steps.position')
                ->limit(1),
        ]);
    }
}
