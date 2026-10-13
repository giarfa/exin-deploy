<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Progetto su cui il team rilascia: contenitore delle proprie release e del
 * proprio storico.
 *
 * I progetti non si cancellano, si disattivano: cancellarne uno porterebbe via
 * lo storico dei rilasci, che e il valore che lo strumento accumula nel tempo.
 *
 * Il template di workflow associato descrive **come** si rilascia su questo
 * progetto; la mappatura ruolo -> persona dice **chi** ne risponde. Servono
 * entrambi: un template i cui step nominano ruoli senza responsabile produce
 * step che nessuno puo chiudere.
 *
 * @property bool $is_active
 * @property string|null $workflow_template_id
 */
#[Fillable(['name', 'slug', 'description', 'is_active', 'workflow_template_id'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Mappatura ruolo -> persona valida su questo progetto.
     *
     * @return HasMany<ProjectRoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectRoleAssignment::class);
    }

    /**
     * Template di workflow che descrive il processo di rilascio del progetto.
     *
     * Nullable: un progetto puo esistere senza template, semplicemente non ci si
     * avviano release.
     *
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    /**
     * Rilasci avviati sul progetto, dal piu recente.
     *
     * E lo storico che rende un progetto non cancellabile: portarselo via
     * significherebbe portarsi via il valore che lo strumento accumula nel tempo
     * (vedi `ProjectPolicy::delete`). Il vincolo e applicato anche dallo schema,
     * con `restrict` sulla chiave esterna di `releases`.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class)->latest('started_at');
    }

    /**
     * Ruoli previsti dagli step del template associato che su questo progetto non
     * hanno un responsabile.
     *
     * E la segnalazione che evita di scoprire il buco all'avvio di una release,
     * quando lo step resterebbe senza nessuno che possa chiuderlo. Da US-013 e
     * un **avviso** e non piu un blocco: chi avvia puo coprire il ruolo indicando
     * li una persona. Cosa questo metodo calcola non e cambiato — e cambiato chi
     * lo interpreta, e come.
     *
     * Percorso a rischio N+1 per costruzione, perche compare in elenco: quando le
     * relazioni sono gia precaricate questo metodo **non** interroga il database.
     * `loadMissing` e la rete di sicurezza per l'uso su un singolo progetto, non
     * una licenza a chiamarlo dentro un ciclo senza eager loading.
     *
     * @return Collection<int, Role>
     */
    public function uncoveredRoles(): Collection
    {
        $this->loadMissing(['workflowTemplate.stepDefinitions.role', 'assignments']);

        $template = $this->workflowTemplate;

        if ($template === null) {
            return collect();
        }

        $assignedRoleIds = $this->assignments->pluck('role_id')->all();

        return $template->stepDefinitions
            ->pluck('role')
            ->filter()
            ->reject(fn (Role $role): bool => in_array($role->id, $assignedRoleIds, true))
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Responsabili previsti dal processo che risultano disattivati.
     *
     * Un membro disattivato non accede piu: lo step gli verrebbe assegnato e
     * resterebbe fermo. La mappatura resta valida per lo storico, ma per una
     * nuova release va aggiornata — oppure, da US-013, sovrascritta in avvio.
     * Anche questo e un **avviso** e non piu un blocco, per la stessa ragione dei
     * ruoli scoperti.
     *
     * Stessa avvertenza di `uncoveredRoles()`: con le relazioni gia precaricate
     * questo metodo **non** interroga il database, e `loadMissing` e la rete di
     * sicurezza per l'uso su un singolo progetto — non una licenza a chiamarlo
     * dentro un ciclo senza eager loading.
     *
     * @return Collection<int, User>
     */
    public function inactiveResponsibles(): Collection
    {
        $this->loadMissing(['workflowTemplate.stepDefinitions', 'assignments.user']);

        $template = $this->workflowTemplate;

        if ($template === null) {
            return collect();
        }

        $neededRoles = $template->stepDefinitions->pluck('role_id')->unique();

        return $this->assignments
            ->whereIn('role_id', $neededRoles)
            ->pluck('user')
            ->filter()
            ->reject(fn (User $user): bool => $user->is_active)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Ruoli previsti dal processo con il proprio responsabile predefinito, uno per
     * ruolo anche quando il processo lo usa su piu step.
     *
     * E il dato su cui la schermata di avvio disegna un selettore per ruolo. La
     * persona predefinita e `null` in due casi che a chi avvia si presentano allo
     * stesso modo — una scelta da fare — anche se hanno origini diverse: il ruolo
     * non ha alcuna assegnazione sul progetto, oppure ne ha una su una persona
     * disattivata. Un disattivato non e selezionabile, quindi non e nemmeno un
     * valore che si possa precompilare: proporlo significherebbe offrire una
     * scelta che l'avvio poi rifiuta.
     *
     * L'ordine e quello del **primo step che usa il ruolo** e non alfabetico: la
     * schermata segue la catena, e un elenco ordinato per nome costringerebbe a
     * ricostruire mentalmente il processo.
     *
     * Stessa avvertenza N+1 di `uncoveredRoles()` e `inactiveResponsibles()`: con
     * le relazioni gia precaricate questo metodo **non** interroga il database, e
     * `loadMissing` e la rete di sicurezza per l'uso su un singolo progetto — non
     * una licenza a chiamarlo dentro un ciclo senza eager loading.
     *
     * Torna una **lista** e non una `Collection`: qui serve una sequenza ordinata
     * su cui la vista itera, e niente a valle usa i metodi di Collection. Non e
     * una preferenza — un array shape dentro un generic invariante fa riportare a
     * Larastan un disallineamento fra due tipi identici, e piegare il codice per
     * aggirarlo costerebbe piu della forma piu semplice.
     *
     * @return list<array{role: Role, default: User|null, requires_choice: bool}>
     */
    public function responsibleChoices(): array
    {
        $this->loadMissing(['workflowTemplate.stepDefinitions.role', 'assignments.user']);

        $template = $this->workflowTemplate;

        /** @var list<array{role: Role, default: User|null, requires_choice: bool}> $choices */
        $choices = [];

        if ($template === null) {
            return $choices;
        }

        $byRole = $this->assignments->keyBy('role_id');

        foreach ($template->stepDefinitions->unique('role_id') as $definition) {
            $role = $definition->role;

            if ($role === null) {
                continue;
            }

            $responsible = $byRole->get($definition->role_id)?->user;

            if ($responsible !== null && ! $responsible->is_active) {
                $responsible = null;
            }

            $choices[] = [
                'role' => $role,
                'default' => $responsible,
                'requires_choice' => $responsible === null,
            ];
        }

        return $choices;
    }

    /**
     * Motivo per cui non si puo avviare una release sul progetto, `null` quando
     * si puo.
     *
     * Vive sul modello e non sul componente di avvio perche serve in due posti —
     * la schermata di avvio e l'elenco progetti, che disabilita il comando con il
     * motivo accanto — e due copie della stessa regola divergerebbero.
     *
     * Un motivo alla volta, nell'ordine in cui vanno risolti: elencarli tutti
     * insieme non aiuterebbe, perche il secondo si vede solo dopo aver sistemato
     * il primo. Le precondizioni sono le stesse verificate da
     * `App\Actions\Releases\StartRelease`, che resta l'unico percorso che decide
     * davvero: qui si anticipa il rifiuto, non lo si sostituisce.
     *
     * **Da US-013 (FR-029) restano solo le regole che nessun override puo
     * sanare.** Ruolo scoperto e responsabile predefinito disattivato erano qui e
     * ne sono usciti di proposito: sono esattamente i casi che la scelta dei
     * responsabili in avvio esiste per coprire, e tenerli come blocco
     * impedirebbe la funzione mentre si e nel punto in cui la si userebbe.
     * Restano **segnalati** — da `uncoveredRoles()` e `inactiveResponsibles()`,
     * che non sono cambiati — ma non impediscono piu nulla. Chi li rimettesse qui
     * credendo di riparare una dimenticanza spegnerebbe US-013.
     */
    public function startBlocker(): ?string
    {
        if (! $this->is_active) {
            return __('releases.blocked_inactive_project');
        }

        $template = $this->workflowTemplate;

        if ($template === null) {
            return __('releases.blocked_without_template');
        }

        if (! $template->isUsable()) {
            return __((string) $template->unusableReason());
        }

        return null;
    }

    /**
     * Il binding di rotta resta sull'identificativo e non sullo slug: lo slug e
     * modificabile, quindi un collegamento gia diffuso si romperebbe alla prima
     * rinomina. Lo slug e un identificativo leggibile, non un indirizzo stabile.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Progetti attivi, gli unici su cui si avviano nuove release.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
