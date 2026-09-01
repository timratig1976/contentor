<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Quellen-Monitor täglich laufen lassen (prüft fällige crawl-Quellen,
// vergleicht Content-Hashes, extrahiert bei Änderungen neue Angles)
Schedule::command('monitor:sources')->dailyAt('07:00');
