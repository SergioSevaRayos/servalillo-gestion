<?php

namespace App\Services;

use App\Models\Device;
use App\Models\GpsPosition;
use App\Models\Route;
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

        /** @var array<string, Route|null> $routeByDate */
        $routeByDate = [];
        $rows = [];

        foreach ($positions as $p) {
            $recordedAt = Carbon::parse($p['recorded_at']);

            // El reloj del móvil puede ir adelantado; se descartan posiciones "del futuro".
            if ($recordedAt->greaterThan($future)) {
                continue;
            }

            $dateKey = $recordedAt->toDateString();

            if (! array_key_exists($dateKey, $routeByDate)) {
                $routeByDate[$dateKey] = $device->driver_id
                    ? Route::forDate($dateKey)->where('driver_id', $device->driver_id)->first()
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
