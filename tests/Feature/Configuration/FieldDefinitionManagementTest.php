<?php

namespace Tests\Feature\Configuration;

use App\Enums\FieldType;
use App\Models\FieldDefinition;
use App\Models\StepDefinition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class FieldDefinitionManagementTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowTemplate $template;

    private StepDefinition $step;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->template = WorkflowTemplate::factory()->create();
        $this->step = StepDefinition::factory()->for($this->template)->create();
    }

    private function fields(): Testable
    {
        return Livewire::test('templates.fields', [
            'template' => $this->template,
            'stepDefinition' => $this->step,
        ]);
    }

    public function test_the_form_offers_exactly_the_four_types_from_the_enum(): void
    {
        $this->get(route('templates.fields', [$this->template, $this->step]))->assertOk();

        $component = $this->fields()->call('openCreateForm');

        foreach (FieldType::cases() as $type) {
            $component->assertSee($type->label());
        }
    }

    public function test_an_empty_step_explains_that_it_is_a_legitimate_choice(): void
    {
        $this->get(route('templates.fields', [$this->template, $this->step]))
            ->assertOk()
            ->assertSee(__('templates.fields_empty'));
    }

    public function test_an_administrator_adds_a_field_of_each_type(): void
    {
        foreach (FieldType::cases() as $index => $type) {
            $this->fields()
                ->call('openCreateForm')
                ->set('label', 'Campo '.$type->label())
                ->set('type', $type->value)
                ->set('isRequired', $index % 2 === 0)
                ->set('helpText', 'Aiuto per '.$type->label())
                ->call('save')
                ->assertHasNoErrors();
        }

        $fields = $this->step->fieldDefinitions()->get();

        $this->assertSame([1, 2, 3, 4], $fields->pluck('position')->all());
        $this->assertEqualsCanonicalizing(
            array_column(FieldType::cases(), 'value'),
            $fields->pluck('type')->map(fn (FieldType $type): string => $type->value)->all()
        );
        $this->assertSame([true, false, true, false], $fields->pluck('is_required')->all());
    }

    public function test_a_type_outside_the_enum_is_rejected_on_the_server(): void
    {
        // Non basta che non sia nel menu: la validazione lo rifiuta comunque.
        $this->fields()
            ->call('openCreateForm')
            ->set('label', 'Firma digitale')
            ->set('type', 'firma_digitale')
            ->call('save')
            ->assertHasErrors('type');

        $this->assertSame(0, $this->step->fieldDefinitions()->count());
    }

    public function test_a_field_requires_a_label_and_a_type(): void
    {
        $this->fields()
            ->call('openCreateForm')
            ->set('label', '')
            ->set('type', '')
            ->call('save')
            ->assertHasErrors(['label' => 'required', 'type' => 'required']);
    }

    public function test_an_administrator_edits_a_field(): void
    {
        $field = FieldDefinition::factory()->for($this->step)->create();

        $this->fields()
            ->call('openEditForm', $field->id)
            ->set('label', 'Versione consegnata')
            ->set('isRequired', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Versione consegnata', $field->fresh()->label);
        $this->assertFalse($field->fresh()->is_required);
    }

    public function test_reordering_and_deleting_keep_the_positions_contiguous(): void
    {
        FieldDefinition::factory()->count(4)->for($this->step)->create();

        $last = $this->step->fieldDefinitions()->where('position', 4)->firstOrFail();

        $this->fields()->call('moveUp', $last->id);
        $this->assertSame(3, $last->fresh()->position);

        $this->fields()->call('delete', $last->id);
        $this->assertSame([1, 2, 3], $this->step->fieldDefinitions()->pluck('position')->all());
    }

    public function test_a_step_of_another_template_is_not_reachable_by_identifier(): void
    {
        $foreign = StepDefinition::factory()->create();

        $this->get(route('templates.fields', [$this->template->id, $foreign->id]))->assertNotFound();
    }

    public function test_a_field_of_another_step_is_not_reachable_through_the_actions(): void
    {
        $foreign = FieldDefinition::factory()->create();

        try {
            $this->fields()->call('delete', $foreign->id);
            $this->fail('Un campo di un altro step non deve essere raggiungibile.');
        } catch (ModelNotFoundException) {
            // Atteso: la ricerca avviene dentro lo step della rotta.
        }

        $this->assertDatabaseHas('field_definitions', ['id' => $foreign->id]);
    }

    public function test_a_member_cannot_invoke_the_livewire_actions(): void
    {
        $field = FieldDefinition::factory()->for($this->step)->create();

        $this->actingAs(User::factory()->member()->create());

        $this->fields()->call('openCreateForm')->assertForbidden();
        $this->fields()->call('openEditForm', $field->id)->assertForbidden();
        $this->fields()->call('moveUp', $field->id)->assertForbidden();
        $this->fields()->call('moveDown', $field->id)->assertForbidden();
        $this->fields()->call('delete', $field->id)->assertForbidden();

        $this->fields()
            ->set('label', 'Tentativo')
            ->set('type', FieldType::ShortText->value)
            ->call('save')
            ->assertForbidden();
    }

    public function test_the_listing_does_not_query_per_row(): void
    {
        FieldDefinition::factory()->count(8)->for($this->step)->create();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->fields()->assertOk();

        $this->assertLessThan(6, $queries, "Eseguite {$queries} query: sospetto N+1 sull'elenco dei campi.");
    }
}
