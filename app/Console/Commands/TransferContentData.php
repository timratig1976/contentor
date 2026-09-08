<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TransferContentData extends Command
{
    protected $signature = 'content:transfer';
    protected $description = 'Exportiert die aktuellen Content-Daten als JSON und gibt Anweisungen für den Import auf dem Zielgerät.';

    public function handle(): int
    {
        $tables = [
            'strategies', 'content_strategies', 'angles', 'sources',
            'settings', 'agent_logs', 'monitoring_events',
        ];

        $dataDir = database_path('seeders/data');

        if (! is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $this->info('📤 Exportiere Content-Daten...');
        $this->newLine();

        foreach ($tables as $table) {
            $rows = \DB::table($table)->get()->toArray();
            $file = "{$dataDir}/{$table}.json";
            file_put_contents(
                $file,
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $this->line("  ✅ {$table}: " . count($rows) . " Zeilen → {$table}.json");
        }

        $this->newLine();
        $this->info('📦 Dateien liegen unter: database/seeders/data/');
        $this->newLine();
        $this->line('═══ Transfer auf das Zielgerät ═══');
        $this->line('1. Kopiere den Ordner <info>database/seeders/data/</info> auf das Zielgerät');
        $this->line('2. Kopiere <info>database/seeders/ContentTransferSeeder.php</info> auf das Zielgerät');
        $this->line('3. Auf dem Zielgerät:');
        $this->line('   <info>php artisan db:seed --class=ContentTransferSeeder</info>');
        $this->newLine();

        return self::SUCCESS;
    }
}