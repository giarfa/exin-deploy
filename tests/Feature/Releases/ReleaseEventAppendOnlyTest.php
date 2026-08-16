<?php

namespace Tests\Feature\Releases;

use App\Enums\ReleaseEventAction;
use App\Exceptions\ReleaseEventIsAppendOnly;
use App\Models\ReleaseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
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

    public function test_no_release_route_accepts_a_writing_method(): void
    {
        /*
         * Il criterio non chiede che il **modello** rifiuti — quello esiste dai
         * casi qui sopra — ma che *nessuna rotta* consenta di modificare o
         * cancellare una voce. E un'affermazione sull'applicazione, e va verificata
         * come tale: il rifiuto del modello non dimostra l'assenza di un percorso,
         * dimostra solo che quel percorso fallirebbe.
         *
         * Le rotte delle release sono tutte di lettura, registro incluso. Chi
         * introdurra il primo percorso di scrittura dovra passare da questo test.
         */
        $writing = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'rilasci')
                || str_starts_with($route->uri(), 'step'))
            ->filter(fn (RoutingRoute $route): bool => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
            ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' /'.$route->uri())
            ->values()
            ->all();

        // Il messaggio nomina la rotta inattesa: un fallimento che dice solo
        // "non corrisponde" costringe a cercarla a mano.
        $this->assertSame([], $writing, 'Rotte di scrittura sulle superfici di rilascio: '.implode(', ', $writing));
    }

    public function test_no_route_binds_a_release_event(): void
    {
        // Nessuna rotta risolve un evento dall'indirizzo: il registro si legge per
        // release, e una voce non e un oggetto su cui si agisce.
        $bound = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route): bool => in_array('releaseEvent', $route->parameterNames(), true))
            ->map(fn (RoutingRoute $route): string => '/'.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $bound, 'Rotte che risolvono un evento del registro: '.implode(', ', $bound));
    }

    public function test_the_register_screen_exposes_no_writing_action(): void
    {
        /*
         * In Livewire ogni metodo pubblico del componente e invocabile dal browser
         * come azione, quindi "nessuna funzione dell'interfaccia" e un'affermazione
         * sui metodi pubblici e non solo sui bottoni resi. Fissare l'elenco atteso
         * fa fallire l'aggiunta del primo metodo che scriva, invece di lasciarla
         * passare perche nessun bottone lo richiama ancora.
         *
         * Restano `mount`, che il framework invoca al montaggio e non su richiesta
         * del browser, e le proprieta calcolate, che `#[Computed]` non rende
         * invocabili come azioni. La superficie di **azione** della pagina e quindi
         * vuota, ed e cio che il criterio chiede.
         */
        $source = File::get(resource_path('views/components/releases/⚡log.blade.php'));

        preg_match_all('/^\s*public function (\w+)\(/m', $source, $matches);

        $declared = collect($matches[1])->sort()->values()->all();

        $this->assertSame(
            ['detailByEvent', 'entries', 'icons', 'mount'],
            $declared,
            'Il registro ha guadagnato un metodo pubblico: in Livewire e un\'azione invocabile dal browser. '
            .'Se e di sola lettura, aggiungilo all\'elenco atteso; se scrive, non appartiene a questa schermata.'
        );
    }

    public function test_no_console_command_writes_on_the_register(): void
    {
        // "Nessun comando" e parte del criterio: oggi l'applicazione non ne ha
        // alcuno, e il test lo dichiara invece di lasciarlo implicito — cosi il
        // primo comando che toccasse gli eventi si presenterebbe qui.
        $commands = File::exists(app_path('Console'))
            ? File::allFiles(app_path('Console'))
            : [];

        foreach ($commands as $command) {
            $this->assertStringNotContainsString(
                'ReleaseEvent',
                File::get($command->getPathname()),
                "Il comando {$command->getFilename()} nomina il registro delle transizioni."
            );
        }

        // Nessun comando esiste oggi: l'asserzione dichiara la situazione invece
        // di lasciare il caso senza verifiche.
        $this->assertSame([], $commands);
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
