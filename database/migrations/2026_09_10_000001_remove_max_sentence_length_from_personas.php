<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attribut war nur eine weiche Prompt-Zeile ohne Durchsetzung —
     * ersatzlos entfernt (Modell, Controller, Seeders, UI bereinigt).
     */
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('max_sentence_length');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_sentence_length')->nullable()->after('forbidden_words');
        });
    }
};
