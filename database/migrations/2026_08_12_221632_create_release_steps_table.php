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
        Schema::create('release_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Gli step di istanza non sopravvivono alla propria release: fuori da
            // essa non hanno significato.
            $table->foreignUuid('release_id')->constrained()->cascadeOnDelete();
            // `unsignedInteger` e non `integer` con segno come in `step_definitions`:
            // lo snapshot non si riordina, quindi non serve il passaggio da
            // posizioni temporanee negative che la rinumerazione di una definizione
            // richiede (vedi OrderedByPosition).
            $table->unsignedInteger('position');
            // Nome e istruzioni sono **copiati**, non letti dalla definizione: e
            // questa copia a rendere la release insensibile alle modifiche del
            // template dopo l'avvio.
            $table->string('name');
            $table->text('instructions')->nullable();
            // `restrict`: un ruolo responsabile di uno step di release non e
            // cancellabile (vedi Role::REFERENCING_RELATIONS).
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            // Il nome del ruolo e congelato accanto alla chiave esterna: rinominare
            // un ruolo non deve riscrivere lo storico dei rilasci gia eseguiti.
            // L'esecuzione legge `role_name`; `role_id` serve solo a rendere il
            // ruolo non cancellabile.
            $table->string('role_name');
            // Il responsabile e risolto **all'avvio** dalla mappatura del progetto:
            // cambiarla dopo non riassegna gli step delle release gia avviate.
            $table->foreignUuid('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // La posizione identifica lo step dentro la release: due step alla
            // stessa posizione renderebbero l'ordine della catena indecidibile.
            $table->unique(['release_id', 'position']);
            // Vista "i miei step" (US-007): filtra per responsabile e stato.
            $table->index(['assigned_user_id', 'status']);
            $table->index('role_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_steps');
    }
};
