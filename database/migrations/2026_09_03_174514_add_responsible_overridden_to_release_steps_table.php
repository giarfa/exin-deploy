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
        Schema::table('release_steps', function (Blueprint $table) {
            /*
             * Il responsabile di questo step e stato **indicato in avvio** invece di
             * essere ereditato dalla mappatura del progetto (US-013, FR-029).
             *
             * Una colonna sullo snapshot e non una tabella di override: il dettaglio
             * della release deve poter disegnare la segnalazione senza join e senza
             * tornare a leggere `project_role_assignments`, che a release avviata e
             * vietata (vedi `.ai/rules/app.md`).
             *
             * Nessun indice: non e una colonna di filtro ne di ordinamento, e su
             * SQLite un indice booleano a bassa selettivita non paga.
             *
             * Il default `false` e cio che rende questa migrazione applicabile alle
             * release gia esistenti senza backfill, e non e un ripiego: uno step
             * storico **non** e stato sovrascritto, e dichiararlo e corretto.
             */
            $table->boolean('responsible_overridden')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('release_steps', function (Blueprint $table) {
            $table->dropColumn('responsible_overridden');
        });
    }
};
