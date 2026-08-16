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
        Schema::create('release_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('release_id')->constrained()->cascadeOnDelete();
            // Nullable: l'avvio e la conclusione di una release non riguardano un
            // singolo step.
            $table->foreignUuid('release_step_id')->nullable()->constrained('release_steps')->cascadeOnDelete();
            // `restrict`: l'attore di un evento non e cancellabile, altrimenti il
            // registro direbbe "qualcuno" invece di dire chi.
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action')->index();
            // Dettagli specifici dell'evento (etichetta, template di origine,
            // numero di step congelati): variano per tipo, e una colonna per
            // ciascuno sarebbe quasi sempre nulla.
            $table->json('payload')->nullable();
            // Solo `created_at`, e non `timestamps()`: una riga di questo registro
            // non si modifica, quindi `updated_at` sarebbe una colonna che dichiara
            // possibile cio che il modello rifiuta (vedi ReleaseEvent::booted).
            $table->timestamp('created_at');

            // Lettura cronologica del registro di una release (US-010).
            $table->index(['release_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_events');
    }
};
