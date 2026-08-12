<?php

namespace Tests\Unit\Models;

use App\Enums\ReleaseStatus;
use App\Models\Release;
use App\Models\ReleaseStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->assertNotNull($release->completedBy);
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
}
