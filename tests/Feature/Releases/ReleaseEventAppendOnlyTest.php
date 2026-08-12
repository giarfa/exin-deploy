<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseEventAction;
use App\Exceptions\ReleaseEventIsAppendOnly;
use App\Models\ReleaseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Il registro delle transizioni e la prova di cosa e successo durante un
 * rilascio: se una riga fosse correggibile a posteriori, la ricostruzione di un
 * rilascio contestato varrebbe quanto il ricordo di chi lo racconta.
 */
class ReleaseEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_cannot_be_updated(): void
    {
        $event = ReleaseEvent::factory()->create();

        $this->expectException(ReleaseEventIsAppendOnly::class);

        $event->update(['action' => ReleaseEventAction::StepCompleted]);
    }

    public function test_an_event_cannot_be_deleted(): void
    {
        $event = ReleaseEvent::factory()->create();

        $this->expectException(ReleaseEventIsAppendOnly::class);

        $event->delete();
    }

    public function test_a_refused_update_leaves_the_row_untouched(): void
    {
        $event = ReleaseEvent::factory()->create();

        try {
            $event->update(['action' => ReleaseEventAction::StepCompleted]);
        } catch (ReleaseEventIsAppendOnly) {
            // Il rifiuto e il comportamento atteso: qui interessa cosa resta in tabella.
        }

        $this->assertDatabaseHas('release_events', [
            'id' => $event->id,
            'action' => ReleaseEventAction::ReleaseStarted->value,
        ]);
    }

    public function test_the_table_has_no_updated_at_column(): void
    {
        // Una colonna che dichiara possibile cio che il modello rifiuta sarebbe
        // una promessa non mantenuta dallo schema.
        $this->assertFalse(Schema::hasColumn('release_events', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('release_events', 'created_at'));
    }

    public function test_the_payload_is_read_back_as_an_array(): void
    {
        $event = ReleaseEvent::factory()->create()->fresh();

        $this->assertIsArray($event->payload);
        $this->assertSame('v2.4.0', $event->payload['label']);
    }

    public function test_the_action_vocabulary_covers_every_transition_of_the_register(): void
    {
        $this->assertSame(
            [
                'release_avviata',
                'step_completato',
                'step_attivato',
                'release_conclusa',
                'tentativo_non_autorizzato',
            ],
            array_column(ReleaseEventAction::cases(), 'value')
        );

        foreach (ReleaseEventAction::cases() as $action) {
            $this->assertNotSame('', $action->label());
        }
    }
}
