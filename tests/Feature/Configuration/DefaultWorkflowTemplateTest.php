<?php

namespace Tests\Feature\Configuration;

use App\Actions\Workflows\SetDefaultWorkflowTemplate;
use App\Exceptions\InactiveTemplateCannotBeDefault;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'invariante "un solo template predefinito" non e applicata dallo schema — un
 * indice unico parziale non e portabile fra SQLite, MySQL e PostgreSQL. La
 * contropartita dichiarata e un percorso di scrittura unico piu questi test: se
 * il flag venisse scritto da un secondo percorso, questi test sono il primo posto
 * in cui la cosa si vede.
 */
class DefaultWorkflowTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function action(): SetDefaultWorkflowTemplate
    {
        return app(SetDefaultWorkflowTemplate::class);
    }

    public function test_setting_a_new_default_clears_the_previous_one(): void
    {
        $previous = WorkflowTemplate::factory()->isDefault()->create();
        $next = WorkflowTemplate::factory()->create();

        $this->action()->handle($next);

        $this->assertFalse($previous->fresh()->is_default);
        $this->assertTrue($next->fresh()->is_default);
    }

    public function test_no_sequence_of_operations_leaves_two_defaults(): void
    {
        $templates = WorkflowTemplate::factory()->count(4)->create();

        $this->action()->handle($templates[0]);
        $this->action()->handle($templates[2]);
        $this->action()->handle($templates[2]);
        $this->action()->handle($templates[1]);

        WorkflowTemplate::factory()->count(2)->create();
        $templates[3]->toggleActivation();
        $this->action()->handle($templates[0]);

        $this->assertSame(1, WorkflowTemplate::where('is_default', true)->count());
        $this->assertTrue($templates[0]->fresh()->is_default);
    }

    public function test_a_deactivated_template_cannot_become_the_default(): void
    {
        $template = WorkflowTemplate::factory()->inactive()->create();

        $this->expectException(InactiveTemplateCannotBeDefault::class);

        try {
            $this->action()->handle($template);
        } finally {
            $this->assertFalse($template->fresh()->is_default);
        }
    }

    public function test_deactivating_the_default_template_removes_the_flag(): void
    {
        $template = WorkflowTemplate::factory()->isDefault()->create();

        $template->toggleActivation();

        $this->assertFalse($template->fresh()->is_active);
        $this->assertFalse($template->fresh()->is_default);
    }

    public function test_reactivating_a_template_does_not_hand_it_back_the_default_flag(): void
    {
        $template = WorkflowTemplate::factory()->isDefault()->create();
        $template->toggleActivation();

        $other = WorkflowTemplate::factory()->create();
        $this->action()->handle($other);

        $template->fresh()->toggleActivation();

        $this->assertTrue($template->fresh()->is_active);
        $this->assertFalse($template->fresh()->is_default);
        $this->assertSame(1, WorkflowTemplate::where('is_default', true)->count());
    }
}
