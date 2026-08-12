<?php

namespace Tests\Unit\Enums;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseStepStatus;
use App\Models\Release;
use App\Models\ReleaseStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ValueError;

/**
 * Il vocabolario degli stati e verificato sui due percorsi che contano:
 * costruzione del caso e lettura di una riga scritta aggirando i modelli.
 */
class ReleaseStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_release_has_exactly_two_states(): void
    {
        // Nessun caso "annullata": FR-020 e un requisito Should rinviato dal PRD.
        $this->assertSame(['in_corso', 'conclusa'], array_column(ReleaseStatus::cases(), 'value'));
    }

    public function test_a_step_has_exactly_three_states(): void
    {
        $this->assertSame(
            ['bloccato', 'attivo', 'completato'],
            array_column(ReleaseStepStatus::cases(), 'value')
        );
    }

    public function test_every_state_has_an_italian_label(): void
    {
        $this->assertSame('In corso', ReleaseStatus::InProgress->label());
        $this->assertSame('Conclusa', ReleaseStatus::Completed->label());
        $this->assertSame('Bloccato', ReleaseStepStatus::Blocked->label());
        $this->assertSame('Attivo', ReleaseStepStatus::Active->label());
        $this->assertSame('Completato', ReleaseStepStatus::Completed->label());
    }

    public function test_a_release_state_outside_the_enum_is_rejected(): void
    {
        $this->assertNotContains('annullata', array_column(ReleaseStatus::cases(), 'value'));

        $this->expectException(ValueError::class);

        ReleaseStatus::from('annullata');
    }

    public function test_reading_an_invalid_stored_release_state_fails_loudly(): void
    {
        $release = Release::factory()->create();

        DB::table('releases')->where('id', $release->id)->update(['status' => 'annullata']);

        $this->expectException(ValueError::class);

        Release::findOrFail($release->id)->status;
    }

    public function test_reading_an_invalid_stored_step_state_fails_loudly(): void
    {
        $step = ReleaseStep::factory()->create();

        DB::table('release_steps')->where('id', $step->id)->update(['status' => 'saltato']);

        $this->expectException(ValueError::class);

        ReleaseStep::findOrFail($step->id)->status;
    }
}
