<?php

namespace Tests\Feature\Releases;

use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gli istanti: memorizzati in UTC, mostrati nel fuso dell'applicazione, **uguali su
 * tutte le viste**.
 *
 * L'ultima parte e quella che un test su una schermata sola non dimostra: "coerenti
 * fra le viste" e un'affermazione comparativa, e si verifica confrontando due
 * schermate sullo stesso istante. E anche la parte che si rompe per prima, il
 * giorno in cui una pagina adotta un formato diverso perche a qualcuno stava
 * meglio.
 */
class ReleaseLogInstantTest extends TestCase
{
    use RefreshDatabase;

    public function test_instants_written_by_the_application_are_stored_in_utc(): void
    {
        $written = now();

        $event = ReleaseEvent::factory()->create(['created_at' => $written]);

        /*
         * Letto con il **query builder** e non con l'attributo del modello: il cast
         * Eloquent trasformerebbe il valore prima di mostrarlo, e il test
         * verificherebbe la propria trasformazione invece di cio che sta in tabella.
         */
        $stored = DB::table('release_events')->where('id', $event->id)->value('created_at');

        $this->assertSame(
            $written->clone()->utc()->format('Y-m-d H:i:s'),
            Carbon::parse($stored)->format('Y-m-d H:i:s'),
            'L\'istante in tabella non e in UTC: lo storico dei rilasci diventa illeggibile appena cambia il fuso del server.'
        );
    }

    public function test_a_foreign_timezone_would_be_stored_as_wall_clock_time(): void
    {
        /*
         * **Trappola da conoscere, non difetto da correggere qui.** Eloquent
         * serializza una data formattandola, e il formato non porta l'offset:
         * passando una `Carbon` in un fuso diverso da quello dell'applicazione, in
         * tabella finisce l'orario di parete di quel fuso, non il suo equivalente
         * UTC.
         *
         * L'applicazione non ci cade perche **scrive sempre `now()`**, cioe un
         * istante gia nel fuso dell'applicazione: `StartRelease`, `CloseStep` e
         * `RecordUnauthorizedStepAttempt` non fanno altro. Questo test fissa il
         * comportamento invece di lasciarlo scoprire a chi un giorno passera un
         * istante costruito da un input dell'utente o da un import — che e il
         * momento in cui servira una normalizzazione esplicita, su una tabella in
         * sola aggiunta dove le righe sbagliate non si correggono.
         */
        $roman = Carbon::parse('2026-08-13 21:30:00', 'Europe/Rome');

        $event = ReleaseEvent::factory()->create(['created_at' => $roman]);

        $stored = DB::table('release_events')->where('id', $event->id)->value('created_at');

        $this->assertSame(
            '2026-08-13 21:30:00',
            Carbon::parse($stored)->format('Y-m-d H:i:s'),
            'Il comportamento di serializzazione e cambiato: rivedi la nota su come vengono scritti gli istanti.'
        );

        $this->assertNotSame(
            $roman->clone()->utc()->format('Y-m-d H:i:s'),
            Carbon::parse($stored)->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_application_timezone_is_the_one_the_views_render(): void
    {
        // Il PRD fissa la regola: istanti in UTC, mostrati nel fuso
        // dell'applicazione. Con `app.timezone` a UTC le due coincidono; il giorno
        // in cui la configurazione cambiasse, questo test dice dove guardare.
        $this->assertSame('UTC', config('app.timezone'));

        $event = ReleaseEvent::factory()->create();

        $this->assertSame(
            config('app.timezone'),
            $event->fresh()->created_at->timezoneName,
            'Il cast Eloquent non restituisce l\'istante nel fuso dell\'applicazione.'
        );
    }

    public function test_the_same_instant_reads_identically_on_the_register_and_on_the_detail(): void
    {
        $release = Release::factory()
            ->for(Project::factory()->withTemplate()->create(['name' => 'Portale Clienti']))
            ->create(['label' => 'v2.4.0']);

        $release->forceFill(['started_at' => Carbon::parse('2026-08-10 09:05:00')])->save();

        ReleaseStep::factory()->for($release)->active()->create(['position' => 1]);

        ReleaseEvent::factory()->for($release)->create([
            'created_at' => $release->started_at,
        ]);

        $member = User::factory()->member()->create();
        $rendered = $release->started_at->format('d/m/Y H:i');

        // Lo stesso istante, due schermate: la coerenza si dimostra confrontandole,
        // non ispezionandone una sola.
        $this->actingAs($member)->get(route('releases.log', $release))->assertOk()->assertSee($rendered);
        $this->actingAs($member)->get(route('releases.show', $release))->assertOk()->assertSee($rendered);
    }
}
