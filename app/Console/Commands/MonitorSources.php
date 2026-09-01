<?php

namespace App\Console\Commands;

use App\Services\SourceMonitorService;
use Illuminate\Console\Command;

class MonitorSources extends Command
{
    protected $signature = 'monitor:sources
        {--strategy= : Nur Quellen dieser Strategie prüfen}
        {--force : Alle Quellen prüfen, unabhängig von der Frequenz}';

    protected $description = 'Überwachte Quellen crawlen, Content-Hashes vergleichen und bei Änderungen neue Angles extrahieren';

    public function handle(SourceMonitorService $service): int
    {
        $strategy = $this->option('strategy');
        $force = (bool) $this->option('force');

        $this->info('Prüfe überwachte Quellen' . ($strategy ? " (Strategie: $strategy)" : '') . ' …');

        $result = $service->run($strategy, $force);

        $this->table(
            ['Quelle', 'URL', 'Status', 'Neue Angles'],
            collect($result['details'])->map(fn ($d) => [
                $d['source'],
                \Illuminate\Support\Str::limit($d['url'] ?? '', 50),
                $d['status'],
                $d['angles_created'] ?? '-',
            ]),
        );

        $this->newLine();
        $this->info("Geprüft: {$result['checked']} · Geändert: {$result['changed']} · Fehler: {$result['errors']}");

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
