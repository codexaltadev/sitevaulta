<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler may run on several servers (web + worker servers): every task still runs only once,
// guarded by a lock in the shared cache (database or Redis).
Schedule::alwaysOnOneServer();

Schedule::command('sitevaulta:dispatch-scheduled-backups')->everyMinute()->withoutOverlapping();
Schedule::command('sitevaulta:check-websites-uptime')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitevaulta:check-monitors')->everyMinute()->withoutOverlapping(10);
Schedule::command('sitevaulta:prune-monitor-data')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('sitevaulta:reverify-domains')->everySixHours()->withoutOverlapping();
Schedule::command('sitevaulta:send-monthly-uptime-reports')->monthlyOn(1, '06:00')->withoutOverlapping();
Schedule::command('sitevaulta:check-probe-locations')->everyMinute()->withoutOverlapping();
