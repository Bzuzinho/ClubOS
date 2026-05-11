<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

if (config('clubos.automations.release_invoice_communications_schedule', false)) {
    Schedule::command('comunicacao:libertar-alertas-faturas')->dailyAt('00:05');
}

Schedule::command('finance:activate-due-monthly-fees')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('finance:generate-monthly-fees')->dailyAt('00:20')->withoutOverlapping();
