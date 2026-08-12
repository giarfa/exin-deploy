<?php

namespace Tests\Unit\Enums;

use App\Enums\FieldType;
use App\Models\FieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ValueError;

/**
 * Il criterio di accettazione chiede quattro tipi esatti e il rifiuto di tutto
 * il resto. Il rifiuto e verificato su entrambi i percorsi: costruzione del caso
 * e lettura di una riga scritta aggirando i modelli.
 */
class FieldTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_are_exactly_four_types(): void
    {
        $this->assertSame(
            ['short_text', 'long_text', 'link', 'confirmation'],
            array_column(FieldType::cases(), 'value')
        );
    }

    public function test_every_type_has_a_readable_label(): void
    {
        foreach (FieldType::cases() as $type) {
            $this->assertNotSame('', $type->label());
        }

        $this->assertSame('Testo breve', FieldType::ShortText->label());
        $this->assertSame('Conferma', FieldType::Confirmation->label());
    }

    public function test_a_value_outside_the_enum_is_rejected(): void
    {
        $this->assertNull(FieldType::tryFrom('firma_digitale'));

        $this->expectException(ValueError::class);

        FieldType::from('firma_digitale');
    }

    public function test_reading_an_invalid_stored_type_fails_loudly(): void
    {
        // Un valore fuori enum scritto a mano non resta silenziosamente in giro:
        // il cast solleva alla prima lettura, invece di propagare un tipo ignoto.
        $field = FieldDefinition::factory()->create();

        DB::table('field_definitions')->where('id', $field->id)->update(['type' => 'firma_digitale']);

        $this->expectException(ValueError::class);

        FieldDefinition::findOrFail($field->id)->type;
    }

    public function test_the_type_is_read_back_as_an_enum_case(): void
    {
        $field = FieldDefinition::factory()->link()->create();

        $this->assertSame(FieldType::Link, $field->fresh()->type);
    }
}
