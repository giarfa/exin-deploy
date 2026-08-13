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
        Schema::create('releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // `restrict` su entrambi i riferimenti: ne il progetto ne il template
            // si cancellano proprio perche una release avviata li nomina. Lo
            // storico dei rilasci e il valore che lo strumento accumula.
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            // Il template e la **provenienza** dello snapshot, non una dipendenza:
            // dopo l'avvio nessuna lettura di esecuzione passa da qui. Serve a dire
            // da quale processo la release e nata, e a renderlo non cancellabile.
            $table->foreignUuid('workflow_template_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->string('status')->index();
            $table->foreignUuid('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            // Colonne di conclusione create ora e riempite da US-006: aggiungerle
            // dopo significherebbe una seconda migrazione sulla stessa tabella per
            // una semantica gia decisa dal PRD.
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // L'etichetta identifica il rilascio dentro il progetto: "v2.4.0" due
            // volte sullo stesso progetto renderebbe ambiguo ogni riferimento. Il
            // vincolo vive nello schema e non nella sola validazione, cosi che
            // nemmeno un doppio invio concorrente possa introdurre il duplicato.
            //
            // L'indice composto serve anche da indice sul solo `project_id` (e il
            // suo prefisso): un secondo `index('project_id')` sarebbe ridondante.
            // Vale per gli elenchi filtrati per progetto richiesti da US-009.
            $table->unique(['project_id', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
