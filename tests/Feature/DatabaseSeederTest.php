<?php

namespace Tests\Feature;

use App\Actions\Releases\StartRelease;
use App\Enums\FieldType;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Enums\UserLevel;
use App\Models\DefaultRoleAssignment;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il seeder e l'unica parte del progetto che nessuno esegue in produzione e che
 * **tutti** eseguono per primi: e il comando dopo `composer install`. Se si rompe,
 * si rompe nel momento peggiore — davanti a chi sta valutando se il progetto sta in
 * piedi.
 *
 * I casi piu utili non sono i conteggi, che si rompono a ogni ritocco dello
 * scenario, ma le proprieta che devono restare vere qualunque scenario si semini:
 * nessun record orfano, nessun lorem ipsum, e le invarianti di dominio rispettate —
 * perche uno scenario che le violasse renderebbe verdi test scritti su dati che
 * l'applicazione non puo produrre.
 *
 * **Quattro casi e non dieci**, ed e una scelta e non pigrizia: seminare l'intero
 * ambiente e l'operazione piu lenta della suite (circa un secondo), e con
 * `RefreshDatabase` ogni caso paga il proprio. Dieci casi granulari costavano dieci
 * secondi su una suite che ne dura diciassette. Ogni metodo qui racconta comunque
 * una storia sola.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_configuration_is_complete_and_healthy(): void
    {
        $this->assertSame(1, User::where('level', UserLevel::Admin)->count());
        $this->assertGreaterThanOrEqual(4, User::where('level', UserLevel::Member)->count());

        // Un membro disattivato: serve a verificare il rifiuto dell'accesso senza
        // doverlo creare a mano.
        $this->assertSame(1, User::where('is_active', false)->count());

        // Cinque ruoli, cinque persone designate: senza, il processo dimostrativo
        // racconterebbe un team in cui una sola persona fa tutto.
        $this->assertSame(5, DefaultRoleAssignment::count());

        $projects = Project::with('workflowTemplate')->where('is_active', true)->get();

        $this->assertGreaterThanOrEqual(2, $projects->count());

        $admin = User::where('level', UserLevel::Admin)->firstOrFail();

        foreach ($projects as $project) {
            $this->assertNotNull($project->workflow_template_id, "Il progetto {$project->name} non ha un processo associato.");

            // Nessun ruolo scoperto: lo stato iniziale deve essere quello sano,
            // altrimenti il primo comando che chiunque prova in ambiente
            // dimostrativo — avviare una release — risponde con un rifiuto.
            $this->assertCount(0, $project->uncoveredRoles(), "Il progetto {$project->name} ha ruoli senza responsabile.");
            $this->assertCount(0, $project->inactiveResponsibles(), "Il progetto {$project->name} ha responsabili disattivati.");

            /*
             * Regressione di US-013: senza override la risoluzione dei responsabili
             * resta quella di sempre. L'ambiente dimostrativo non semina nessuna
             * sostituzione — la sua utilita e mostrare il caso normale — quindi e qui
             * che si nota se il parametro nuovo cambiasse il percorso ordinario.
             */
            $expected = $project->assignments->pluck('user_id', 'role_id');

            $release = app(StartRelease::class)->handle(
                $project->fresh(),
                'regressione-'.$project->slug,
                $admin,
            );

            foreach ($release->steps()->get() as $step) {
                $this->assertSame(
                    $expected[$step->role_id],
                    $step->assigned_user_id,
                    "Sul progetto {$project->name} il ruolo {$step->role_name} non ha risolto il responsabile di progetto."
                );
            }
        }

        $template = WorkflowTemplate::where('name', 'Rilascio standard')
            ->with('stepDefinitions.fieldDefinitions')
            ->firstOrFail();

        $this->assertTrue($template->is_default);
        $this->assertCount(5, $template->stepDefinitions);

        $types = $template->stepDefinitions->flatMap->fieldDefinitions->pluck('type')->unique();

        $this->assertCount(
            count(FieldType::cases()),
            $types,
            'Il processo dimostrativo non copre tutti e quattro i tipi di campo.'
        );
    }

    public function test_the_scenario_covers_the_three_shapes_a_release_can_have(): void
    {
        // 1. A meta catena: il primo step chiuso con valori veri, il secondo attivo.
        $halfway = Release::where('label', 'v2.4.0')->with('steps.fields')->firstOrFail();

        $this->assertSame(ReleaseStatus::InProgress, $halfway->status);

        $first = $halfway->steps->firstWhere('position', 1);

        $this->assertSame(ReleaseStepStatus::Completed, $first->status);
        $this->assertSame(ReleaseStepStatus::Active, $halfway->steps->firstWhere('position', 2)->status);
        $this->assertNotNull($first->completed_by);
        $this->assertNotNull($first->completed_at);
        // Con valori veri e non con campi vuoti: una release "a meta catena" senza
        // informazioni fornite non dimostra la meta che conta.
        $this->assertTrue($first->fields->every(fn (ReleaseStepField $field): bool => $field->value !== null));

        // 2. Consegnata: catena tutta chiusa e registro completo.
        $delivered = Release::where('label', 'v2.3.0')->with('steps')->firstOrFail();

        $this->assertSame(ReleaseStatus::Completed, $delivered->status);
        $this->assertNotNull($delivered->completed_by);
        $this->assertNotNull($delivered->completed_at);
        $this->assertTrue($delivered->steps->every(
            fn (ReleaseStep $step): bool => $step->status === ReleaseStepStatus::Completed
        ));

        /*
         * Il registro nasce dalle Action, quindi le sue righe sono quelle che una
         * catena di cinque step produce davvero: avvio, cinque chiusure, quattro
         * attivazioni, conclusione. Contarle e il modo piu diretto per accorgersi
         * che qualcuno le ha sostituite con scritture a mano.
         */
        $this->assertSame(11, ReleaseEvent::where('release_id', $delivered->id)->count());

        // 3. Appena avviata, ferma sul **primo** step: l'unica forma in cui
        // `activationInstant()` non ha un precedente e ripiega su `started_at`, il
        // ramo che ogni rilascio nuovo percorre.
        $justStarted = Release::where('label', '2026.08.1')->with('steps')->firstOrFail();
        $active = $justStarted->steps->firstWhere('status', ReleaseStepStatus::Active);

        $this->assertNotNull($active);
        $this->assertSame(1, $active->position);
        $this->assertSame(0, $justStarted->steps->where('status', ReleaseStepStatus::Completed)->count());
    }

    public function test_the_seeded_data_respects_the_domain_and_has_no_orphans(): void
    {
        foreach (Release::with('steps')->get() as $release) {
            $active = $release->steps->where('status', ReleaseStepStatus::Active)->count();

            // Al massimo uno step attivo per release, **zero** sulle concluse: uno
            // scenario che la violasse renderebbe verdi test scritti su dati che
            // l'applicazione non puo produrre.
            $this->assertSame(
                $release->status === ReleaseStatus::Completed ? 0 : 1,
                $active,
                "La release {$release->label} ha {$active} step attivi."
            );
        }

        $this->assertSame(0, ReleaseStep::whereNull('assigned_user_id')->count(), 'Uno step di release e senza responsabile.');
        $this->assertSame(0, Release::whereNull('workflow_template_id')->count(), 'Una release non nomina il processo da cui e nata.');

        $closedSteps = ReleaseStep::where('status', ReleaseStepStatus::Completed)->pluck('id');

        $this->assertSame(
            0,
            ReleaseStepField::whereIn('release_step_id', $closedSteps)->where('is_required', true)->whereNull('value')->count(),
            'Uno step chiuso ha un campo obbligatorio vuoto: uno stato che la chiusura non puo produrre.'
        );

        // `StartRelease` rifiuta un processo disattivato: un ambiente dimostrativo
        // che contenesse uno stato irriproducibile dall'applicazione direbbe una
        // bugia a chi lo usa per capire come funziona.
        $this->assertSame(
            0,
            Release::whereIn('workflow_template_id', WorkflowTemplate::where('is_active', false)->pluck('id'))->count()
        );
    }

    public function test_the_seeded_text_is_domain_language_and_not_placeholder(): void
    {
        /*
         * Il criterio chiede dati "plausibili per il dominio: nessun lorem ipsum".
         * E scritto a parole e si verifica a parole — un conteggio di righe non
         * distingue una frase di rilascio da un riempitivo.
         */
        $texts = collect()
            ->concat(ReleaseStep::pluck('name'))
            ->concat(ReleaseStep::pluck('instructions'))
            ->concat(ReleaseStepField::pluck('label'))
            ->concat(ReleaseStepField::pluck('value'))
            ->concat(Project::pluck('description'))
            ->filter();

        $this->assertNotEmpty($texts, 'Nessun testo seminato: il caso non sta verificando niente.');

        foreach ($texts as $text) {
            $this->assertStringNotContainsStringIgnoringCase('lorem', $text);
            $this->assertStringNotContainsStringIgnoringCase('ipsum', $text);
        }

        /*
         * "Plausibile" non e solo l'assenza di riempitivi: e anche la coerenza fra
         * quello che una riga dice e il rilascio a cui appartiene. Ogni release
         * dichiara la **propria** versione, non quella di un'altra — un errore di
         * forma giusta e contenuto sbagliato, che si nota leggendo la schermata e
         * mai contando le righe.
         */
        foreach (Release::with('steps.fields')->get() as $release) {
            $declared = $release->steps->flatMap->fields->firstWhere('label', 'Versione rilasciata');

            if ($declared?->value === null) {
                continue;
            }

            $this->assertSame(
                $release->label,
                $declared->value,
                "Il rilascio {$release->label} dichiara di aver consegnato {$declared->value}."
            );
        }
    }
}
