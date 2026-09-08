<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContentTransferSeeder extends Seeder
{
    /**
     * Tabellen in Import-Reihenfolge (Foreign-Key-Abhängigkeiten beachten).
     *
     * WICHTIG: Parents zuerst. `angles.source_id` zeigt auf `sources.id`,
     * deshalb müssen `sources` (und `strategies`) VOR `angles` importiert werden.
     */
    protected array $tables = [
        'strategies',
        'content_strategies',
        'sources',
        'angles',
        'settings',
        'agent_logs',
        'monitoring_events',
    ];

    public function run(): void
    {
        $dataDir = database_path('seeders/data');

        DB::transaction(function () use ($dataDir) {
            // 1) Bestehende Daten in umgekehrter Reihenfolge leeren (Kinder zuerst),
            //    damit Foreign-Key-Constraints beim Löschen nicht verletzt werden.
            foreach (array_reverse($this->tables) as $table) {
                DB::table($table)->delete();
            }

            // 2) Import in Abhängigkeits-Reihenfolge (Parents zuerst).
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

                foreach ($rows as $row) {
                    // IDs BEHALTEN: strategies/sources/angles referenzieren sich
                    // gegenseitig per ID (z. B. angles.strategy_id -> strategies.id).
                    DB::table($table)->insert($row);
                }

                $this->command?->info("✅ {$table}: " . count($rows) . " Zeilen importiert.");
            }
        });
    }
}