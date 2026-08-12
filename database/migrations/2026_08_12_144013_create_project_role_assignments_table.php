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
        Schema::create('project_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            // `restrict`: un ruolo referenziato da una mappatura non e cancellabile,
            // resta disattivabile. Vale anche per i membri, che non si cancellano.
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            // Una sola persona per coppia progetto/ruolo, applicato dallo schema.
            $table->unique(['project_id', 'role_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_role_assignments');
    }
};
