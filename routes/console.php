<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Matag adlaw: i-deactivate ang mga establishment nga na-expire na ang contract.
// Kinahanglan nga naka-run ang scheduler (cron: * * * * * php artisan schedule:run, o `php artisan schedule:work` sa local).
Schedule::command('datenest:deactivate-expired')->daily();

// Matag adlaw: i-delete ang mga na-reject nga registration nga 2 ka adlaw na ang miagi.
Schedule::command('datenest:purge-rejected')->daily();

// Matag buntag: mga reminder (ma-expire na ang ads; ugma na ang reservation).
Schedule::command('datenest:send-reminders')->dailyAt('08:00');