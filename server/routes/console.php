<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Paradas de clientes con calendario fijo (L·X·V…): se generan cada madrugada.
Schedule::command('rutas:generar-recurrentes')->dailyAt('05:30');
