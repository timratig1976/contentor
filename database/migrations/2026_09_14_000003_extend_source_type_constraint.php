<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Erweitert das type-CHECK-Constraint der sources-Tabelle um die neuen
     * Source-Intelligence-Typen: rss, screenshot, community, video, audio.
     *
     * SQLite unterstützt kein ALTER CONSTRAINT — daher Tabelle neu aufbauen
     * (create → copy → drop → rename), eingebettet in eine Transaktion.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'sqlite') {
            // MySQL/Postgres: Constraint via change() erweitern
            Schema::table('sources', function ($table) {
                $table->enum('type', ['pdf', 'url', 'interview', 'intern', 'research', 'rss', 'screenshot', 'community', 'video', 'audio'])->change();
            });
            return;
        }

        DB::transaction(function () {
            $sql = DB::select("SELECT sql FROM sqlite_master WHERE type='table' AND name='sources'");
            $oldSql = $sql[0]->sql ?? null;
            if (! $oldSql) {
                return;
            }

            // CHECK-Constraint im CREATE-Statement ersetzen
            $newSql = preg_replace(
                "/check \(\"type\" in \('pdf', 'url', 'interview', 'intern', 'research'\)\)/i",
                "check (\"type\" in ('pdf', 'url', 'interview', 'intern', 'research', 'rss', 'screenshot', 'community', 'video', 'audio'))",
                $oldSql
            );

            // Falls altes Constraint nicht gefunden (z.B. bereits migriert): abbrechen
            if ($newSql === $oldSql) {
                return;
            }

            $newSql = str_replace('CREATE TABLE "sources"', 'CREATE TABLE "sources_new"', $newSql);

            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement($newSql);

            $columns = DB::select('PRAGMA table_info(sources)');
            $cols = array_map(fn ($c) => '"' . $c->name . '"', $columns);
            $colList = implode(', ', $cols);

            DB::statement("INSERT INTO sources_new ({$colList}) SELECT {$colList} FROM sources");
            DB::statement('DROP TABLE sources');
            DB::statement('ALTER TABLE sources_new RENAME TO sources');

            // Indizes wiederherstellen (wurden mit der Tabelle gedroppt)
            DB::statement('CREATE INDEX IF NOT EXISTS sources_strategy_id_index ON sources (strategy_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS sources_type_index ON sources (type)');
            DB::statement('PRAGMA foreign_keys=ON');
        });
    }

    public function down(): void
    {
        // Kein Rollback des Constraints — vorhandene rss/screenshot-Zeilen
        // würden dagegen verstoßen; Rückbau nur manuell nach Datenbereinigung.
    }
};
