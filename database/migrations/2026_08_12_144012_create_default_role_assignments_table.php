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
        Schema::create('default_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Unico per ruolo: una sola persona predefinita per ruolo, applicato
            // dallo schema e non solo dalla validazione.
            $table->foreignUuid('role_id')->unique()->constrained()->restrictOnDelete();
            // `restrict` verso i membri: non si cancellano mai, si disattivano.
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('default_role_assignments');
    }
};
