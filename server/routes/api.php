<?php

use App\Http\Controllers\Api\DeviceApiController;
use App\Http\Controllers\Api\GpsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de tracking GPS (Bloque 10)
|--------------------------------------------------------------------------
| La única API de la app: la consume una APK "tracker" sin interfaz que el servicio
| técnico instala en el móvil de cada camión. La operativa del chofer vive en la web
| (Livewire), no aquí.
*/

// Enrolamiento: la APK envía su install_identifier + el secreto compartido y recibe un
// token Sanctum con habilidad `gps:ingest`. Limitado por IP.
Route::post('device/register', [DeviceApiController::class, 'register'])
    ->middleware('throttle:device-register');

// Lote de posiciones (offline-friendly). Solo tokens de dispositivo con la habilidad.
Route::post('gps/batch', [GpsController::class, 'store'])
    ->middleware(['auth:sanctum', 'abilities:gps:ingest']);
