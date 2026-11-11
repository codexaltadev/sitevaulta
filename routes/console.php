<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sitevaulta:dispatch-scheduled-backups')->everyMinute()->withoutOverlapping();
Schedule::command('sitevaulta:check-websites-uptime')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitevaulta:check-monitors')->everyMinute()->withoutOverlapping(10);
