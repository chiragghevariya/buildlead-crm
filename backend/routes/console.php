<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily ERP housekeeping (run `php artisan schedule:work` locally, or cron `* * * * * php artisan schedule:run`).
Schedule::command('invoices:mark-overdue')->dailyAt('06:00')->timezone('Europe/Berlin')->withoutOverlapping();
