<?php

namespace Tests\Unit\Models;

use App\Enums\ReleaseStatus;
use App\Models\Release;
use App\Models\ReleaseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_receives_a_uuid_primary_key(): void
    {
        $release = Release::factory()->create();

        $this->assertTrue(Str::isUuid($release->id), "L'id [{$release->id}] non e un UUID valido.");
    }

    public function test_it_belongs_to_a_project_a_template_and_an_author(): void
    {
        $release = Release::factory()->create();

        $this->assertNotNull($release->project);
        $this->assertNotNull($release->workflowTemplate);
        $this->assertNotNull($release->startedBy);
    }

    public function test_a_new_release_is_in_progress_and_has_no_conclusion(): void
    {
        $release = Release::factory()->create()->fresh();

        $this->assertSame(ReleaseStatus::InProgress, $release->status);
        $this->assertNull($release->completed_by);
        $this->assertNull($release->completed_at);
    }

    public function test_a_completed_release_records_who_closed_it_and_when(): void
    {
        $release = Release::factory()->completed()->create()->fresh();

        $this->assertSame(ReleaseStatus::Completed, $release->status);
        // Chi conclude e chi ha avviato, non una terza persona comparsa dal nulla:
        // uno scostamento qui produrrebbe dati di prova che raccontano il falso su
        // cio che `CloseStep` scrive davvero chiudendo l'ultimo step.
        $this->assertSame($release->started_by, $release->completed_by);
        $this->assertInstanceOf(Carbon::class, $release->completed_at);
    }

    public function test_the_started_at_column_is_read_back_as_a_date(): void
    {
        $this->assertInstanceOf(Carbon::class, Release::factory()->create()->fresh()->started_at);
    }

    public function test_the_steps_relation_is_ordered_by_position(): void
    {
        $release = Release::factory()->create();

        ReleaseStep::factory()->for($release)->create(['position' => 3, 'name' => 'Terzo']);
        ReleaseStep::factory()->for($release)->create(['position' => 1, 'name' => 'Primo']);
        ReleaseStep::factory()->for($release)->create(['position' => 2, 'name' => 'Secondo']);

        $this->assertSame(
            ['Primo', 'Secondo', 'Terzo'],
            $release->steps()->pluck('name')->all()
        );
    }

    public function test_a_project_lists_its_releases(): void
    {
        $release = Release::factory()->create();

        $this->assertTrue($release->project->releases->contains($release));
    }

    public function test_the_in_progress_scope_filters_out_completed_releases(): void
    {
        $running = Release::factory()->create();
        $done = Release::factory()->completed()->create();

        $found = Release::query()->inProgress()->pluck('id');

        $this->assertTrue($found->contains($running->id));
        $this->assertFalse($found->contains($done->id));
    }

    public function test_the_active_step_relation_returns_the_step_the_release_is_stopped_on(): void
    {
        $release = Release::factory()->create();

        ReleaseStep::factory()->for($release)->completed()->create(['position' => 1]);
        $open = ReleaseStep::factory()->for($release)->active()->create(['position' => 2]);
        ReleaseStep::factory()->for($release)->blocked()->create(['position' => 3]);

        $this->assertSame($open->id, $release->activeStep->id);
    }

    public function test_a_completed_release_has_no_active_step(): void
    {
        // `null` qui e l'esito legittimo dell'invariante — al piu uno step attivo
        // per release, zero quando e conclusa — e non un difetto dei dati: chi
        // rende una release conclusa deve prevederlo.
        $release = Release::factory()->completed()->create();

        ReleaseStep::factory()->for($release)->completed()->create(['position' => 1]);

        $this->assertNull($release->activeStep);
    }

    public function test_the_active_step_is_eager_loadable(): void
    {
        // E il motivo per cui e una relazione e non un metodo che filtra la catena
        // gia caricata: un elenco che risalisse allo step attivo riga per riga
        // pagherebbe una query per release.
        Release::factory()->count(3)->create()->each(
            fn (Release $release) => ReleaseStep::factory()->for($release)->active()->create(['position' => 1])
        );

        $releases = Release::query()->with('activeStep')->get();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $names = $releases->map(fn (Release $release): string => (string) $release->activeStep?->name);

        $this->assertCount(3, $names);
        $this->assertSame(0, $queries, "La lettura dello step attivo ha eseguito {$queries} query: l'eager loading non ha preso.");
    }

    public function test_the_involving_scope_finds_releases_where_the_person_holds_any_step(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        $held = Release::factory()->create();
        ReleaseStep::factory()->for($held)->completed()->create(['position' => 1, 'assigned_user_id' => $member->id]);
        // Lo stato dello step non entra nel filtro: chi ha gia chiuso il proprio
        // resta coinvolto nel rilascio, ed e proprio a lui che serve sapere su chi
        // si e fermato dopo.
        ReleaseStep::factory()->for($held)->active()->create(['position' => 2, 'assigned_user_id' => $other->id]);

        $foreign = Release::factory()->create();
        ReleaseStep::factory()->for($foreign)->active()->create(['position' => 1, 'assigned_user_id' => $other->id]);

        $found = Release::query()->involving($member)->pluck('id');

        $this->assertTrue($found->contains($held->id));
        $this->assertFalse($found->contains($foreign->id));
    }
}
