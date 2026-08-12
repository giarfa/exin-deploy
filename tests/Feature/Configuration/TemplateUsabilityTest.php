<?php

namespace Tests\Feature\Configuration;

use App\Models\StepDefinition;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un template senza step non e utilizzabile per avviare una release, e il motivo
 * deve essere esplicito: "disattivato" e "senza step" si risolvono in due modi
 * diversi. US-004 leggera `isUsable()` come precondizione dell'avvio.
 */
class TemplateUsabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_template_without_steps_is_not_usable(): void
    {
        $template = WorkflowTemplate::factory()->create();

        $this->assertFalse($template->isUsable());
        $this->assertSame('templates.unusable_without_steps', $template->unusableReason());
    }

    public function test_a_deactivated_template_is_not_usable_and_says_so_differently(): void
    {
        $template = WorkflowTemplate::factory()->inactive()->create();
        StepDefinition::factory()->for($template)->create();

        $this->assertFalse($template->isUsable());
        $this->assertSame('templates.unusable_inactive', $template->unusableReason());
    }

    public function test_an_active_template_with_at_least_one_step_is_usable(): void
    {
        $template = WorkflowTemplate::factory()->withSteps(1)->create();

        $this->assertTrue($template->isUsable());
        $this->assertNull($template->unusableReason());
    }

    public function test_removing_the_last_step_makes_the_template_unusable_again(): void
    {
        $template = WorkflowTemplate::factory()->withSteps(1)->create();

        $template->stepDefinitions()->first()->deleteAndResequence();

        $this->assertFalse($template->fresh()->isUsable());
        $this->assertSame('templates.unusable_without_steps', $template->fresh()->unusableReason());
    }

    public function test_the_usability_check_reuses_a_preloaded_count(): void
    {
        // Il metodo compare per riga in elenco: se ignorasse `withCount()` il
        // conteggio messo per evitare l'N+1 non servirebbe a nulla.
        WorkflowTemplate::factory()->count(3)->withSteps(2)->create();

        $templates = WorkflowTemplate::query()->withCount('stepDefinitions')->get();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach ($templates as $template) {
            $this->assertTrue($template->isUsable());
            $this->assertNull($template->unusableReason());
        }

        $this->assertSame(0, $queries, "Eseguite {$queries} query: il conteggio precaricato non viene riusato.");
    }
}
