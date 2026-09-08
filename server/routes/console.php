<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Paradas de clientes con calendario fijo (L·X·V…): se generan cada madrugada.
Schedule::command('rutas:generar-recurrentes')->dailyAt('05:30');

// Retención de posiciones GPS (Bloque 10): purga las anteriores a gps_retention_days.
Schedule::command('gps:purgar')->dailyAt('04:00');
