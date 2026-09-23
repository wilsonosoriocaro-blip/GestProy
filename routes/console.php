<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily digest of due and overdue work. Runs every weekday; the command
// itself skips Colombian holidays and never sends twice the same day.
Schedule::command('projects:send-alerts')
    ->weekdays()
    ->at(config('projects.notifications.digest_time'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
