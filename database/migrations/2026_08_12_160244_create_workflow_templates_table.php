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
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Il nome e l'identificativo con cui il team parla del processo: due
            // template omonimi renderebbero ambigua ogni scelta in elenco.
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            // "Un solo predefinito" non e applicato dallo schema: un indice unico
            // parziale non e portabile fra SQLite, MySQL e PostgreSQL con le
            // migrazioni di Laravel. L'invariante vive nella Action
            // SetDefaultWorkflowTemplate, unico percorso di scrittura del flag.
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_templates');
    }
};
