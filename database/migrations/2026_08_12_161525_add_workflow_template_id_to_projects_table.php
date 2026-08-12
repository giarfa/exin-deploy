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
        Schema::table('projects', function (Blueprint $table) {
            // Nullable: un progetto senza template resta uno stato legittimo —
            // semplicemente non ci si avviano release (US-004 lo fara rispettare).
            // `restrict` perche un template non si cancella: si disattiva.
            $table->foreignUuid('workflow_template_id')
                ->nullable()
                ->after('description')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_template_id');
        });
    }
};
