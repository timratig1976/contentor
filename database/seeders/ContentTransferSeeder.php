<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContentTransferSeeder extends Seeder
{
    /**
     * Tabellen in Import-Reihenfolge (Foreign-Key-Abhängigkeiten beachten).
     */
    protected array $tables = [
        'strategies',
        'content_strategies',
        'angles',
        'sources',
        'settings',
        'agent_logs',
        'monitoring_events',
    ];

    public function run(): void
    {
        $dataDir = database_path('seeders/data');

        foreach ($this->tables as $table) {
            $file = "{$dataDir}/{$table}.json";

            if (! file_exists($file)) {
                $this->command?->warn("⚠️  {$table}.json nicht gefunden – überspringe.");
                continue;
            }

            $rows = json_decode(file_get_contents($file), true);

            if (! is_array($rows) || empty($rows)) {
                $this->command?->info("ℹ️  {$table}: keine Daten.");
                continue;
            }

            // Leere die Tabelle vor dem Import (nur wenn nicht leer)
            if (DB::table($table)->count() > 0) {
                DB::table($table)->truncate();
            }

            foreach ($rows as $row) {
                // Entferne auto-increment IDs, damit die Ziel-DB eigene IDs vergibt
                unset($row['id']);

                // Timestamps behalten wir bei
                DB::table($table)->insert($row);
            }

            $this->command?->info("✅ {$table}: " . count($rows) . " Zeilen importiert.");
        }
    }
}