<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Both reconciliation jobs are engine round trips, so they run in the small
// hours (app.timezone is UTC) rather than during the import window, and never
// on the same minute. The 30 minute overlap lock releases on its own so a run
// that dies mid-sweep does not block the next day.
Schedule::command('sync:import-status --limit=100')
    ->dailyAt('02:15')
    ->withoutOverlapping(30)
    ->description('Reconcile dataset status with the AI engine import jobs');

Schedule::command('sync:quality --limit=100 --days=30')
    ->dailyAt('02:45')
    ->withoutOverlapping(30)
    ->description('Re-run stale quality checks for committed datasets');
