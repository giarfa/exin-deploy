<?php

namespace Tests\Feature\Releases;

use App\Actions\Releases\StartRelease;
use App\Models\FieldDefinition;
use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Release;
use App\Models\Role;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_reading_a_release_never_touches_a_definition_table(): void
    {
        // E la regola numero uno di `.ai/rules/app.md`, e non si dimostra
        // guardando il codice: senza questo test la prima lettura comoda di
        // `stepDefinitions` dentro l'esecuzione la aggira senza che nessuno se ne
        // accorga.
        $project = $this->projectReadyToRelease();

        $release = app(StartRelease::class)->handle($project, 'v2.4.0', User::factory()->admin()->create());

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

        $steps = Release::query()
            ->whereKey($release->id)
            ->with(['steps.fields', 'steps.assignedUser'])
            ->firstOrFail()
            ->steps;

        // Ogni attributo letto e raccolto: e la lettura a poter far scattare un
        // caricamento pigro, e quindi una query che il test deve poter vedere.
        $read = $steps
            ->map(fn ($step): string => implode(' / ', [
                $step->name,
                $step->role_name,
                $step->assignedUser->name,
                $step->fields->pluck('label')->implode(', '),
            ]))
            ->all();

        $this->assertCount($steps->count(), $read);

        // Senza questa riga il test passerebbe anche se l'osservatore non vedesse
        // nulla, cioe dimostrando zero.
        $this->assertGreaterThan(0, $observed, 'Nessuna query osservata: il test non sta misurando niente.');

        $this->assertSame(
            [],
            array_values(array_unique($touched)),
            'La lettura di una release ha interrogato una tabella di definizione: lo snapshot non e piu la sola fonte di verita.'
        );
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
