<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\CloseStep;
use App\Actions\Releases\StartRelease;
use App\Enums\FieldType;
use App\Enums\ReleaseStepStatus;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\ReleaseStepField;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Il test che vale la spec: dopo l'avvio, la definizione puo cambiare in ogni
 * modo e la release non se ne accorge.
 *
 * E il motivo per cui esistono quattro tabelle di istanza invece di quattro
 * riferimenti. Senza questa copertura, la prima lettura comoda di
 * `stepDefinitions` dentro l'esecuzione passerebbe inosservata fino al giorno in
 * cui qualcuno modifica un template e lo storico dei rilasci cambia forma.
 */
class SnapshotIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tabelle che descrivono **come si rilascia**, e che l'esecuzione di una
     * release non deve mai interrogare.
     *
     * `project_role_assignments` e in elenco insieme alle tre della definizione:
     * la mappatura ruolo -> persona e risolta all'avvio e congelata sullo step,
     * quindi rileggerla in esecuzione riporterebbe dentro la release un dato che
     * puo cambiare.
     *
     * @var list<string>
     */
    private const DEFINITION_TABLES = [
        'workflow_templates',
        'step_definitions',
        'field_definitions',
        'project_role_assignments',
    ];

    public function test_changing_the_template_after_the_start_does_not_touch_the_release(): void
    {
        $project = $this->projectReadyToRelease();
        $template = $project->workflowTemplate;

        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

        $before = $this->shapeOf($release);

        // Rinomina di uno step, riordino di due, cancellazione di un terzo.
        $definitions = $template->stepDefinitions()->get();

        $definitions[0]->update(['name' => 'Nome cambiato dopo l\'avvio', 'instructions' => 'Istruzioni riscritte.']);
        $definitions[1]->update(['position' => 90]);
        $definitions[2]->update(['position' => 91]);
        $definitions[2]->delete();

        // Etichetta e obbligatorieta di un campo, e un campo in piu.
        $field = FieldDefinition::where('step_definition_id', $definitions[0]->id)->orderBy('position')->first();
        $field->update(['label' => 'Etichetta cambiata', 'is_required' => ! $field->is_required]);
        FieldDefinition::factory()->for($definitions[0])->create(['label' => 'Campo aggiunto dopo']);

        // Rinomina del ruolo e disattivazione del template.
        Role::query()->whereKey($definitions[0]->role_id)->first()->update(['name' => 'Ruolo rinominato']);
        $template->update(['is_active' => false]);

        $this->assertSame($before, $this->shapeOf($release->fresh()));
    }

    public function test_changing_the_project_mapping_after_the_start_does_not_reassign_the_steps(): void
    {
        $project = $this->projectReadyToRelease();

        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

        $before = $release->steps()->pluck('assigned_user_id', 'position');

        $replacement = User::factory()->create();

        foreach ($project->assignments as $assignment) {
            $assignment->update(['user_id' => $replacement->id]);
        }

        $this->assertEquals($before, $release->fresh()->steps()->pluck('assigned_user_id', 'position'));
    }

    public function test_the_release_stays_readable_after_the_definitions_are_deleted(): void
    {
        $project = $this->projectReadyToRelease();

        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

        $before = $this->shapeOf($release);

        // Cancellare gli step di definizione porta via anche i loro campi
        // (`cascade`): se lo snapshot fosse un riferimento, qui la release
        // diventerebbe illeggibile.
        StepDefinition::where('workflow_template_id', $project->workflow_template_id)->delete();

        $this->assertSame($before, $this->shapeOf($release->fresh()));
    }

    public function test_a_frozen_step_still_shows_the_original_role_name(): void
    {
        $project = $this->projectReadyToRelease();

        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

        $step = $release->steps()->first();
        $original = $step->role_name;

        Role::query()->whereKey($step->role_id)->first()->update(['name' => 'Nome nuovo del ruolo']);

        $this->assertSame($original, $step->fresh()->role_name);
        $this->assertNotSame($original, Role::query()->whereKey($step->role_id)->value('name'));
    }

    public function test_the_screen_that_reads_a_release_never_touches_a_definition_table(): void
    {
        /*
         * E la regola numero uno di `.ai/rules/app.md`, e non si dimostra
         * guardando il codice.
         *
         * Il test osserva la **schermata**, non una query scritta qui dentro: una
         * lettura costruita dal test dimostrerebbe soltanto che il test non tocca
         * le definizioni, e resterebbe verde anche se domani il pannello di
         * conferma leggesse `stepDefinitions`. Cosi invece quel cambiamento la fa
         * fallire, che e il punto.
         */
        $project = $this->projectReadyToRelease();
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start')
            ->assertHasNoErrors();

        // L'ascolto comincia **dopo** l'avvio: durante l'avvio le tabelle di
        // definizione vengono lette per forza, ed e proprio quella la copia.
        $touched = [];
        $observed = 0;

        DB::listen(function (QueryExecuted $query) use (&$touched, &$observed): void {
            $observed++;

            foreach (self::DEFINITION_TABLES as $table) {
                if (str_contains($query->sql, '"'.$table.'"') || str_contains($query->sql, ' '.$table.' ')) {
                    $touched[] = $table;
                }
            }
        });

        // Un nuovo ciclo di richiesta: le proprieta calcolate vengono rivalutate,
        // quindi la catena congelata viene riletta davvero.
        $component->call('$refresh')->assertSee(__('releases.chain_heading'));

        $this->assertGreaterThan(0, $observed, 'Nessuna query osservata: il test non sta misurando niente.');

        $this->assertSame(
            [],
            array_values(array_unique($touched)),
            'La schermata di una release ha interrogato una tabella di definizione: lo snapshot non e piu la sola fonte di verita.'
        );
    }

    public function test_the_release_detail_reads_the_template_only_for_its_name(): void
    {
        /*
         * Il dettaglio della release (US-008) e l'unico percorso di lettura che
         * interroga `workflow_templates`, e la deroga e voluta: il criterio di
         * accettazione chiede il **template di origine**, cioe da dove la release e
         * nata. Il nome mostrato e quello attuale, e la nota accanto lo dichiara —
         * catena, ordine e campi arrivano tutti dallo snapshot.
         *
         * Il template non e cancellabile finche una release lo referenzia
         * (`restrictOnDelete` su `releases.workflow_template_id`), quindi la lettura
         * non puo rompersi. Le altre tre tabelle restano vietate, e questo test lo
         * dimostra sulla **pagina** e non su una query scritta qui dentro: una
         * lettura costruita dal test resterebbe verde anche se domani il componente
         * risalisse a `stepDefinitions` per sapere cosa uno step chiedeva.
         */
        $project = $this->projectReadyToRelease();
        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

        $names = $release->steps()->pluck('name');

        // Le definizioni non esistono piu: se il dettaglio ne dipendesse, la pagina
        // perderebbe la catena invece di mostrarla congelata.
        StepDefinition::where('workflow_template_id', $project->workflow_template_id)->delete();

        $forbidden = array_values(array_diff(self::DEFINITION_TABLES, ['workflow_templates']));

        $touched = [];
        $observed = 0;

        DB::listen(function (QueryExecuted $query) use (&$touched, &$observed, $forbidden): void {
            $observed++;

            foreach ($forbidden as $table) {
                if (str_contains($query->sql, '"'.$table.'"') || str_contains($query->sql, ' '.$table.' ')) {
                    $touched[] = $table;
                }
            }
        });

        // Un membro qualunque: la lettura del dettaglio e aperta a chiunque sia
        // autenticato, e il percorso da verificare e quello che usera la maggioranza.
        $response = $this->actingAs(User::factory()->member()->create())
            ->get(route('releases.show', $release))
            ->assertOk();

        $this->assertGreaterThan(0, $observed, 'Nessuna query osservata: il test non sta misurando niente.');

        $this->assertSame(
            [],
            array_values(array_unique($touched)),
            'Il dettaglio della release ha interrogato una tabella di definizione: lo snapshot non e piu la sola fonte di verita.'
        );

        foreach ($names as $name) {
            $response->assertSee($name);
        }
    }

    public function test_closing_a_step_never_touches_a_definition_table(): void
    {
        /*
         * L'avanzamento e il punto in cui la tentazione e piu forte: per sapere
         * "cosa viene dopo" e "cosa chiedeva questo step" basterebbe leggere il
         * template. Se lo facesse, riordinare un template cambierebbe l'ordine di
         * una release gia in corso, e le regole di validazione applicate oggi
         * sarebbero quelle di adesso invece di quelle congelate all'avvio.
         *
         * Il test osserva la **chiusura vera**, non una query scritta qui dentro.
         */
        $project = $this->projectReadyToRelease();

        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

        $step = $release->steps()->with('fields')->firstOrFail();
        $actor = $step->assignedUser;

        // L'ascolto comincia **dopo** l'avvio: durante l'avvio le tabelle di
        // definizione vengono lette per forza, ed e proprio quella la copia.
        $touched = [];
        $observed = 0;

        DB::listen(function (QueryExecuted $query) use (&$touched, &$observed): void {
            $observed++;

            foreach (self::DEFINITION_TABLES as $table) {
                if (str_contains($query->sql, '"'.$table.'"') || str_contains($query->sql, ' '.$table.' ')) {
                    $touched[] = $table;
                }
            }
        });

        app(CloseStep::class)->handle($step, $this->valuesFor($step), $actor);

        $this->assertGreaterThan(0, $observed, 'Nessuna query osservata: il test non sta misurando niente.');

        $this->assertSame(
            [],
            array_values(array_unique($touched)),
            'La chiusura di uno step ha interrogato una tabella di definizione: lo snapshot non e piu la sola fonte di verita.'
        );
    }

    public function test_the_chain_advances_after_the_definitions_are_deleted(): void
    {
        // Seconda prova dello stesso criterio, per la strada opposta: se
        // l'avanzamento dipendesse dalle definizioni, cancellarle lo romperebbe.
        $project = $this->projectReadyToRelease();

        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

        $step = $release->steps()->with('fields')->firstOrFail();

        StepDefinition::where('workflow_template_id', $project->workflow_template_id)->delete();

        app(CloseStep::class)->handle($step, $this->valuesFor($step), $step->assignedUser);

        $this->assertSame(ReleaseStepStatus::Completed, $step->fresh()->status);
        $this->assertSame(
            ReleaseStepStatus::Active,
            $release->steps()->where('position', 2)->firstOrFail()->status
        );
    }

    public function test_the_frozen_chain_is_readable_without_any_definition_table(): void
    {
        // Seconda prova dello stesso criterio, per la strada opposta: se il
        // percorso di lettura dipendesse dalle definizioni, cancellarle lo
        // romperebbe. Qui la schermata continua a mostrare la catena intera.
        $project = $this->projectReadyToRelease();
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test('releases.start', ['project' => $project])
            ->set('label', 'v2.4.0')
            ->call('start');

        $release = Release::query()->where('project_id', $project->id)->firstOrFail();
        $names = $release->steps()->pluck('name');

        StepDefinition::where('workflow_template_id', $project->workflow_template_id)->delete();

        $component->call('$refresh');

        foreach ($names as $name) {
            $component->assertSee($name);
        }
    }

    /**
     * Valori validi per ogni campo dello step, secondo il tipo congelato.
     *
     * @return array<string, mixed>
     */
    private function valuesFor(ReleaseStep $step): array
    {
        return $step->fields
            ->mapWithKeys(fn (ReleaseStepField $field): array => [
                $field->id => match ($field->type) {
                    FieldType::ShortText => '2.4.0',
                    FieldType::LongText => 'Verifica completata senza anomalie bloccanti.',
                    FieldType::Link => 'https://ci.gruppoexcellence.com/pipeline/4471',
                    FieldType::Confirmation => true,
                },
            ])
            ->all();
    }

    /**
     * Forma completa della catena congelata: quanto basta perche una qualsiasi
     * modifica alla definizione, se filtrasse, faccia fallire il confronto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shapeOf(Release $release): array
    {
        return $release->steps()->with('fields')->get()
            ->map(fn ($step): array => [
                'position' => $step->position,
                'name' => $step->name,
                'instructions' => $step->instructions,
                'role_name' => $step->role_name,
                'assigned_user_id' => $step->assigned_user_id,
                'status' => $step->status->value,
                'fields' => $step->fields
                    ->map(fn ($field): array => [
                        'position' => $field->position,
                        'label' => $field->label,
                        'type' => $field->type->value,
                        'is_required' => $field->is_required,
                        'help_text' => $field->help_text,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Progetto pronto a rilasciare, con tre step e campi su ciascuno.
     */
    private function projectReadyToRelease(): Project
    {
        $template = WorkflowTemplate::factory()->create();
        $roles = Role::factory()->count(3)->create();

        foreach ($roles as $position => $role) {
            $step = StepDefinition::factory()->for($template)->create([
                'position' => $position + 1,
                'role_id' => $role->id,
            ]);

            FieldDefinition::factory()->count(2)->for($step)->create();
        }

        $project = Project::factory()->withTemplate($template)->create();

        foreach ($roles as $role) {
            ProjectRoleAssignment::factory()->create([
                'project_id' => $project->id,
                'role_id' => $role->id,
                'user_id' => User::factory()->create()->id,
            ]);
        }

        return $project->fresh();
    }
}
