<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * channel_strategies war ein totes Feld: von keiner Logik und keinem
     * Prompt gelesen, bei 0 Personas befüllt — ersatzlos entfernt.
     */
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('channel_strategies');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->json('channel_strategies')->nullable()->after('cadence');
        });
    }
};
