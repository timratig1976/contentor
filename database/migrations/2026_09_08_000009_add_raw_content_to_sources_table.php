<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Rohtext der Quelle (gescrapter Inhalt, PDF-/Datei-Extrakt oder eingefügter Text).
            // Ermöglicht spätere Re-Analyse, ohne erneut crawlen/parsen zu müssen.
            $table->longText('raw_content')->nullable()->after('last_content_preview');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('raw_content');
        });
    }
};