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
        Schema::create('step_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Gli step non sopravvivono al proprio template: fuori da esso non
            // hanno significato.
            $table->foreignUuid('workflow_template_id')->constrained()->cascadeOnDelete();
            // `integer` con segno e non `unsignedInteger`: la rinumerazione passa
            // da posizioni temporanee negative per non violare l'indice unico piu
            // sotto mentre riscrive la sequenza (vedi OrderedByPosition).
            $table->integer('position');
            $table->string('name');
            $table->text('instructions')->nullable();
            // `restrict`: un ruolo responsabile di uno step non e cancellabile,
            // resta disattivabile. Ultima difesa oltre a Role::REFERENCING_RELATIONS.
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            // Posizioni senza duplicati applicate dallo schema, non dalla sola
            // validazione: e il criterio di accettazione sull'ordinamento.
            $table->unique(['workflow_template_id', 'position']);
            $table->index('role_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('step_definitions');
    }
};
