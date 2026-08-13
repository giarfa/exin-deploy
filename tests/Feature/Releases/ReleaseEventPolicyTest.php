<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseEventAction;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * L'autorizzazione sul registro delle transizioni.
 *
 * Due decisioni distinte, che il test tiene insieme: **cosa si puo vedere** — dove
 * i tentativi non autorizzati restano ai soli amministratori — e **cosa non si puo
 * fare a nessuna condizione**, cioe modificare o cancellare una voce, nemmeno da
 * amministratore.
 *
 * La stessa decisione sulla visibilita e scritta due volte, in due linguaggi:
 * `ReleaseEventPolicy::view()` per il singolo evento e `ReleaseEvent::visibleTo()`
 * per la query. Due formulazioni della stessa regola sono due posti in cui
 * divergere, e l'ultimo caso di questo file esiste per impedirlo.
 */
class ReleaseEventPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_register_is_open_to_every_authenticated_member(): void
    {
        // La tracciabilita esiste per essere consultata: un registro visibile ai
        // soli amministratori sarebbe una prova che nessuno degli interessati puo
        // verificare.
        $this->assertTrue(Gate::forUser(User::factory()->member()->create())->allows('viewAny', ReleaseEvent::class));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('viewAny', ReleaseEvent::class));
    }

    public function test_process_transitions_are_visible_to_everyone(): void
    {
        $member = User::factory()->member()->create();

        foreach ($this->processTransitions() as $action) {
            $event = ReleaseEvent::factory()->create(['action' => $action]);

            $this->assertTrue(
                Gate::forUser($member)->allows('view', $event),
                "L'azione {$action->value} dovrebbe essere visibile a ogni membro."
            );
        }
    }

    public function test_an_unauthorized_attempt_is_reserved_to_administrators(): void
    {
        $event = ReleaseEvent::factory()->create([
            'action' => ReleaseEventAction::UnauthorizedAttempt,
        ]);

        // La riga nomina una persona e cosa ha provato a fare: e materiale di
        // sicurezza, non di processo. Mostrarla a tutti trasformerebbe il registro
        // in una lavagna delle colpe.
        $this->assertFalse(Gate::forUser(User::factory()->member()->create())->allows('view', $event));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('view', $event));
    }

    public function test_no_one_may_alter_or_delete_an_entry_not_even_an_administrator(): void
    {
        $event = ReleaseEvent::factory()->create();

        foreach ([User::factory()->member()->create(), User::factory()->admin()->create()] as $user) {
            // Le due ability stanno fra le NOT_FILTERED della Policy: senza,
            // il filtro `before()` le concederebbe a un amministratore proprio
            // dove il vincolo deve valere anche per lui.
            $this->assertFalse(Gate::forUser($user)->allows('update', $event));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $event));
        }
    }

    public function test_the_policy_and_the_query_scope_agree_row_by_row(): void
    {
        $release = Release::factory()->create();
        $step = ReleaseStep::factory()->for($release)->create();

        // Un registro con tutte e cinque le azioni: e l'insieme su cui le due
        // formulazioni possono divergere.
        foreach ($this->processTransitions() as $action) {
            ReleaseEvent::factory()->for($release)->create(['action' => $action]);
        }

        ReleaseEvent::factory()->unauthorizedAttempt($step)->create();

        foreach ([User::factory()->member()->create(), User::factory()->admin()->create()] as $user) {
            $allowedByPolicy = ReleaseEvent::query()
                ->forRelease($release)
                ->get()
                ->filter(fn (ReleaseEvent $event): bool => Gate::forUser($user)->allows('view', $event))
                ->pluck('id')
                ->sort()
                ->values();

            $returnedByScope = ReleaseEvent::query()
                ->forRelease($release)
                ->visibleTo($user)
                ->pluck('id')
                ->sort()
                ->values();

            $this->assertEquals(
                $allowedByPolicy->all(),
                $returnedByScope->all(),
                "Policy e scope `visibleTo` non concordano per il livello {$user->level->value}: aprirne uno senza l'altro apre una fuga o nasconde una riga legittima."
            );
        }
    }

    /**
     * Le quattro azioni che descrivono l'avanzamento del rilascio, cioe tutte
     * tranne il tentativo non autorizzato.
     *
     * @return list<ReleaseEventAction>
     */
    private function processTransitions(): array
    {
        return array_filter(
            ReleaseEventAction::cases(),
            fn (ReleaseEventAction $action): bool => $action !== ReleaseEventAction::UnauthorizedAttempt,
        );
    }
}
