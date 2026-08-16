<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('field_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // I campi non sopravvivono al proprio step: fuori da esso non hanno
            // significato.
            $table->foreignUuid('step_definition_id')->constrained()->cascadeOnDelete();
            // `integer` con segno per lo stesso motivo degli step: la rinumerazione
            // passa da posizioni temporanee negative (vedi OrderedByPosition).
            $table->integer('position');
            $table->string('label');
            // Colonna `string` con cast a App\Enums\FieldType, e non `$table->enum`:
            // un vincolo di check costringerebbe SQLite a ricostruire la tabella al
            // primo tipo aggiunto. Il rifiuto di un valore fuori enum resta pieno —
            // `Rule::enum` in scrittura, ValueError del cast in lettura.
            $table->string('type');
            $table->boolean('is_required')->default(false);
            $table->string('help_text')->nullable();
            $table->timestamps();

            // Posizioni senza duplicati applicate dallo schema.
            $table->unique(['step_definition_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
    }
};
