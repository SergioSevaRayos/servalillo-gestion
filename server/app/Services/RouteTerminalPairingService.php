<?php

namespace App\Services;

use App\Models\Device;
use App\Models\RouteDay;
use App\Models\RouteTerminal;
use App\Models\User;

/**
 * Terminal vinculado a una ruta (petición del usuario): si el navegador desde el que un chofer
 * inicia sesión lleva la cookie `route_terminal` (puesta al visitar el enlace de vinculación, ver
 * App\Http\Controllers\RouteTerminalController), ese chofer pasa a gestionar la ruta vinculada ese
 * día — pensado para que un sustituto cubra la ruta de un compañero de baja sin que oficina tenga
 * que reasignar nada a mano. Sin pantalla de confirmación, a petición del usuario ("pasa
 * directamente"). Llamado desde la rama `isDriver()` de la ruta `home`.
 */
class RouteTerminalPairingService
{
    public function __construct(private RecurringRouteService $recurringRoutes) {}

    /** @return string nombre de ruta al que redirigir (siempre 'chofer.today'; lo que importa es el efecto secundario) */
    public function resolveDriverHome(User $user): string
    {
        $driver = $user->driver;

        if ($driver === null) {
            return 'chofer.today';
        }

        $token = request()->cookie('route_terminal');

        if (! $token) {
            return 'chofer.today';
        }

        $terminal = RouteTerminal::where('token', $token)->whereNull('revoked_at')->first();

        if ($terminal === null) {
            return 'chofer.today';
        }

        $routeDay = $this->recurringRoutes->ensureForDate($terminal->route, today());

        if ($routeDay->driver_id === $driver->id) {
            $terminal->forceFill(['last_used_at' => now()])->saveQuietly();

            return 'chofer.today';
        }

        // Guarda de colisión: si el chofer ya lleva su propia ruta hoy, no se sustituye — acabaría
        // con dos RouteDay del mismo driver_id la misma fecha, y varias piezas del GPS/tiempo de
        // permanencia (StopDwellService, RouteGeometry, RouteOptimizer) asumen que eso no pasa.
        $ownRouteDayToday = RouteDay::where('driver_id', $driver->id)
            ->whereDate('route_date', today())
            ->whereKeyNot($routeDay->id)
            ->exists();

        if ($ownRouteDayToday) {
            $terminal->forceFill(['last_used_at' => now()])->saveQuietly();

            return 'chofer.today';
        }

        $original = $routeDay->driver_id;
        $routeDay->update(['driver_id' => $driver->id]); // auditado solo (RouteDay es Auditable)

        if ($original !== null) {
            $device = Device::where('driver_id', $original)->first();
            $substituteHasOwnDevice = Device::where('driver_id', $driver->id)->exists();

            if ($device !== null && ! $substituteHasOwnDevice) {
                $device->update(['driver_id' => $driver->id]); // auditado solo
            }
        }

        $terminal->forceFill(['last_used_at' => now()])->saveQuietly();

        return 'chofer.today';
    }
}
