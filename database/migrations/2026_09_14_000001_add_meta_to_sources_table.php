<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sources.meta: typ-spezifische Zustandsdaten als JSON
     * (RSS: bekannte Item-GUIDs, Video: Chapters, OCR: Confidence …).
     * Verhindert Spalten-Inflation pro neuem Quelltyp.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('raw_content');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
