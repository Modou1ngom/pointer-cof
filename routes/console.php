<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pointage:feries-import-nager')
    ->yearlyOn(1, 2, '3:00')
    ->timezone(config('app.timezone', 'UTC'));

Schedule::command('pointage:feries-prolonger-recurrence')
    ->yearlyOn(1, 1, '4:00')
    ->timezone(config('app.timezone', 'UTC'));

Schedule::command('pointage:feries-auto-pointage')
    ->dailyAt('00:05')
    ->timezone(config('app.timezone', 'UTC'));

// Complète la sortie auto férié après l’heure de départ prévue.
Schedule::command('pointage:feries-auto-pointage')
    ->dailyAt('17:10')
    ->timezone(config('app.timezone', 'UTC'));
