<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('communication:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(5);

if (config('clubos.automations.release_invoice_communications_schedule', false)) {
    Schedule::command('comunicacao:libertar-alertas-faturas')->dailyAt('00:05');
}

if (config('clubos.automations.monthly_fee_scheduler', false)) {
    Schedule::command('finance:generate-monthly-fees')->dailyAt('00:10')->withoutOverlapping();
    Schedule::command('finance:activate-due-monthly-fees')->dailyAt('00:20')->withoutOverlapping();
}
