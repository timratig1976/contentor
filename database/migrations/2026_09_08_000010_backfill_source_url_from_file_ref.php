<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sources')) {
            return;
        }

        // Historische url-Quellen speichern die URL in `file_ref` (älteres Feld).
        // In die dedizierte `url`-Spalte übernehmen, damit Monitoring & UI
        // die URL nicht erneut abfragen müssen.
        DB::table('sources')
            ->whereNull('url')
            ->where('type', 'url')
            ->whereNotNull('file_ref')
            ->where('file_ref', 'like', 'http%')
            ->update([
                'url' => DB::raw('file_ref'),
            ]);
    }

    public function down(): void
    {
        // Kein Datenverlust-Rückweg nötig — file_ref bleibt unverändert.
    }
};