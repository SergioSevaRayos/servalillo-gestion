<?php

namespace App\Services;

use App\Models\Device;
use App\Models\GpsPosition;
use App\Models\RouteDay;
use Illuminate\Support\Carbon;

/**
 * Ingesta de lotes de posiciones GPS de un dispositivo (Bloque 10).
 *
 * El chofer de cada posición = el chofer asignado al dispositivo. El camión y la ruta se
 * deducen de la ruta de ese chofer para la fecha de la posición (puede no haber → null).
 */
class GpsIngestService
{
    /**
     * @param  list<array{lat: float|string, lng: float|string, recorded_at: string, accuracy_m?: float|string|null, speed_mps?: float|string|null, heading_deg?: float|string|null, battery_level?: int|null}>  $positions
     */
    public function ingest(Device $device, array $positions): int
    {
        abort_if(! $device->is_active, 403, 'Dispositivo desactivado.');

        $now = now();
        $future = $now->copy()->addHour();

        /** @var array<string, RouteDay|null> $routeByDate */
        $routeByDate = [];
        $rows = [];

        foreach ($positions as $p) {
            // La APK manda `recorded_at` en UTC (`DateTime.toUtc().toIso8601String()`). Hay que
            // convertirlo a la zona de la app ANTES de guardarlo: `gps_positions.recorded_at` es
            // un `timestamp` sin zona (como `route_days.started_at`/`completed_at`, siempre
            // escritos con `now()` ya en hora local) — si se guarda la hora UTC tal cual, al leerla
            // se reinterpreta como si ya fuera hora local y queda desplazada (2 h en verano/CEST,
            // 1 h en invierno/CET) respecto a cualquier comparación con `now()`, `started_at`, etc.
            // Bug real detectado en producción (2026-09-11): una parada con paso real por su
            // geocerca a las 08:58 hora de Madrid se guardaba como 06:58 y quedaba fuera de la
            // ventana de jornada (`clamp_to_shift`) y "vieja" para el indicador "en parada ahora".
            $recordedAt = Carbon::parse($p['recorded_at'])->setTimezone(config('app.timezone'));

            // El reloj del móvil puede ir adelantado; se descartan posiciones "del futuro".
            if ($recordedAt->greaterThan($future)) {
                continue;
            }

            $dateKey = $recordedAt->toDateString();

            if (! array_key_exists($dateKey, $routeByDate)) {
                $routeByDate[$dateKey] = $device->driver_id
                    ? RouteDay::forDate($dateKey)->where('driver_id', $device->driver_id)->first()
                    : null;
            }

            $route = $routeByDate[$dateKey];

            $rows[] = [
                'device_id' => $device->id,
                'driver_id' => $device->driver_id,
                'truck_id' => $route?->truck_id,
                'route_id' => $route?->id,
                'latitude' => $p['lat'],
                'longitude' => $p['lng'],
                'accuracy_m' => $p['accuracy_m'] ?? null,
                'speed_mps' => $p['speed_mps'] ?? null,
                'heading_deg' => $p['heading_deg'] ?? null,
                'battery_level' => $p['battery_level'] ?? null,
                'recorded_at' => $recordedAt,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            GpsPosition::insert($rows);
        }

        $device->forceFill(['last_seen_at' => $now])->saveQuietly();

        return count($rows);
    }
}
