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
        Schema::create('release_step_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // I campi non sopravvivono al proprio step: fuori da esso non hanno
            // significato.
            $table->foreignUuid('release_step_id')->constrained()->cascadeOnDelete();
            // `unsignedInteger` per lo stesso motivo degli step di release: lo
            // snapshot non si riordina.
            $table->unsignedInteger('position');
            // Etichetta, forma, obbligatorieta e testo di aiuto sono copiati dalla
            // definizione: modificarla dopo l'avvio non cambia cosa questa release
            // chiede di compilare.
            $table->string('label');
            // Colonna `string` con cast a App\Enums\FieldType, per la stessa ragione
            // di `field_definitions`: un vincolo di check costringerebbe SQLite a
            // ricostruire la tabella al primo tipo aggiunto.
            $table->string('type');
            $table->boolean('is_required')->default(false);
            $table->string('help_text')->nullable();
            // Una sola colonna per tutti e quattro i tipi: il valore fornito e
            // sempre testo, e la semantica per tipo — un link e un indirizzo
            // valido, una conferma e "1" — appartiene alla chiusura dello step
            // (US-005). Quattro colonne tipizzate renderebbero tre di esse sempre
            // nulle su ogni riga.
            $table->text('value')->nullable();
            $table->timestamps();

            // La posizione identifica il campo dentro lo step: due campi alla
            // stessa posizione renderebbero l'ordine di compilazione indecidibile.
            $table->unique(['release_step_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_step_fields');
    }
};
