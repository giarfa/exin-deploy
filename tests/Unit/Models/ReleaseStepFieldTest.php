<?php

namespace Tests\Unit\Models;

use App\Enums\FieldType;
use App\Models\ReleaseStepField;
use App\Rules\WellFormedLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le regole di chiusura e la normalizzazione del valore sono la base su cui
 * poggia ogni rifiuto della chiusura: un errore qui si manifesterebbe come un
 * falso rifiuto in produzione, su uno step che il responsabile ha compilato
 * correttamente.
 *
 * I casi sono verificati sul modello e non attraverso l'Action, perche e sul
 * modello che la regola vive — in una sola copia, usata sia dalla schermata sia
 * dalla chiusura.
 */
class ReleaseStepFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_required_field_carries_the_required_rule(): void
    {
        foreach ([FieldType::ShortText, FieldType::LongText, FieldType::Link] as $type) {
            $rules = $this->field($type, required: true)->closingRules();

            $this->assertContains('required', $rules, "Il tipo {$type->value} obbligatorio non porta `required`.");
            $this->assertNotContains('nullable', $rules);
        }
    }

    public function test_an_optional_field_carries_the_nullable_rule(): void
    {
        foreach (FieldType::cases() as $type) {
            $rules = $this->field($type, required: false)->closingRules();

            $this->assertContains('nullable', $rules, "Il tipo {$type->value} opzionale non porta `nullable`.");
            $this->assertNotContains('required', $rules);
        }
    }

    public function test_the_two_text_types_are_bounded_by_different_lengths(): void
    {
        // Un testo breve e un valore puntuale, un testo lungo una spiegazione:
        // lo stesso limite renderebbe il primo un campo note e il secondo un campo
        // troppo corto per quello che deve raccontare.
        $this->assertContains('max:255', $this->field(FieldType::ShortText, required: true)->closingRules());
        $this->assertContains('max:5000', $this->field(FieldType::LongText, required: true)->closingRules());
        $this->assertContains('max:2048', $this->field(FieldType::Link, required: true)->closingRules());
    }

    public function test_only_a_link_field_is_validated_as_an_address(): void
    {
        foreach (FieldType::cases() as $type) {
            $rules = $this->field($type, required: true)->closingRules();

            $carriesTheRule = array_filter($rules, fn (mixed $rule): bool => $rule instanceof WellFormedLink) !== [];

            $this->assertSame(
                $type === FieldType::Link,
                $carriesTheRule,
                "Il tipo {$type->value} non dovrebbe essere validato come indirizzo."
            );
        }
    }

    public function test_a_required_confirmation_must_be_accepted_and_an_optional_one_only_boolean(): void
    {
        $this->assertContains('accepted', $this->field(FieldType::Confirmation, required: true)->closingRules());

        $optional = $this->field(FieldType::Confirmation, required: false)->closingRules();

        $this->assertContains('boolean', $optional);
        $this->assertNotContains('accepted', $optional);
    }

    public function test_every_field_stops_at_the_first_failing_rule(): void
    {
        // `bail` non e cosmetico: senza, un obbligatorio vuoto fallirebbe
        // `required` **e** `string`, e il riepilogo errori del mockup mostrerebbe
        // due righe per lo stesso difetto sullo stesso campo.
        foreach (FieldType::cases() as $type) {
            $this->assertSame('bail', $this->field($type, required: true)->closingRules()[0]);
        }
    }

    public function test_text_is_trimmed(): void
    {
        $this->assertSame(
            'https://ci.gruppoexcellence.com/pipeline/4471',
            $this->field(FieldType::Link, required: true)->normalizeValue('  https://ci.gruppoexcellence.com/pipeline/4471 ')
        );

        $this->assertSame('2.4.0', $this->field(FieldType::ShortText, required: true)->normalizeValue(" 2.4.0\n"));
    }

    public function test_a_ticked_confirmation_becomes_one_and_an_unticked_one_becomes_null(): void
    {
        $confirmation = $this->field(FieldType::Confirmation, required: true);

        $this->assertSame('1', $confirmation->normalizeValue(true));
        $this->assertSame('1', $confirmation->normalizeValue('1'));
        $this->assertSame('1', $confirmation->normalizeValue('on'));

        // `null` e non `'0'`: su un campo che ha una sola direzione, "non ho
        // confermato" e "non ho compilato" sono lo stesso fatto.
        $this->assertNull($confirmation->normalizeValue(false));
        $this->assertNull($confirmation->normalizeValue(null));
        $this->assertNull($confirmation->normalizeValue(''));
    }

    public function test_an_optional_field_left_empty_becomes_null_and_not_an_empty_string(): void
    {
        // US-008 deve poter dire "non fornito": una colonna che contiene `''` non
        // lo consente piu, perche `''` e un valore fornito che si dava il caso
        // fosse vuoto.
        $optional = $this->field(FieldType::LongText, required: false);

        $this->assertNull($optional->normalizeValue(''));
        $this->assertNull($optional->normalizeValue('    '));
        $this->assertNull($optional->normalizeValue(null));
    }

    public function test_a_normalized_optional_field_left_empty_passes_its_own_rules(): void
    {
        // La normalizzazione precede la validazione, e le due devono comporsi:
        // `nullable` salta le regole di forma solo su `null`, non su `''`.
        $optional = $this->field(FieldType::LongText, required: false);

        $errors = validator(
            ['value' => $optional->normalizeValue('   ')],
            ['value' => $optional->closingRules()]
        )->errors();

        $this->assertTrue($errors->isEmpty(), 'Un campo opzionale vuoto e stato rifiutato: '.$errors->first('value'));
    }

    public function test_the_factory_fills_a_value_that_matches_the_type(): void
    {
        // Un campo link con un valore che la validazione rifiuterebbe descrive uno
        // stato che la chiusura non puo produrre.
        foreach (FieldType::cases() as $type) {
            $field = ReleaseStepField::factory()->filled()->make(['type' => $type, 'is_required' => true]);

            $errors = validator(
                ['value' => $field->normalizeValue($field->value)],
                ['value' => $field->closingRules()]
            )->errors();

            $this->assertTrue(
                $errors->isEmpty(),
                "Il valore prodotto per {$type->value} non e valido: ".$errors->first('value')
            );
        }
    }

    public function test_the_factory_accepts_an_explicit_value(): void
    {
        $field = ReleaseStepField::factory()->link()->filled('https://intranet.local/report')->make();

        $this->assertSame('https://intranet.local/report', $field->value);
    }

    /**
     * Campo di snapshot con tipo e obbligatorieta indicati, senza toccare il
     * database: le regole non dipendono dalla persistenza.
     */
    private function field(FieldType $type, bool $required): ReleaseStepField
    {
        return new ReleaseStepField([
            'label' => 'Campo di prova',
            'type' => $type,
            'is_required' => $required,
        ]);
    }
}
