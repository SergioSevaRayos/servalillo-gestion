<?php

namespace App\Services;

use App\Models\GpsPosition;
use App\Models\RouteDay;
use App\Models\RouteStop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Pide a OSRM la geometría real (por carretera) de un recorrido para pintarlo en el
 * mapa de "Ver recorrido" (Bloque 13). Devuelve la línea como pares [lat, lon] más la
 * distancia y duración estimadas; null si OSRM no responde (el mapa cae a línea recta).
 */
class RouteGeometry
{
    /**
     * @param  list<array{0: float, 1: float}>  $points  pares [lat, lon] en orden de parada
     * @return array{line: list<array{0: float, 1: float}>, distance_m: ?float, duration_s: ?float}|null
     */
    public function for(array $points): ?array
    {
        if (! config('servalillo.routing.enabled') || count($points) < 2) {
            return null;
        }

        $coords = implode(';', array_map(fn (array $p) => $p[1].','.$p[0], $points)); // OSRM: lon,lat
        $url = config('servalillo.routing.osrm_url')."/route/v1/driving/{$coords}";

        try {
            $response = Http::connectTimeout((int) config('servalillo.routing.connect_timeout'))
                ->timeout((int) config('servalillo.routing.timeout'))
                ->acceptJson()
                ->get($url, ['overview' => 'full', 'geometries' => 'geojson', 'annotations' => 'false']);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('code') !== 'Ok') {
            return null;
        }

        $route = $response->json('routes.0');
        $coordinates = $route['geometry']['coordinates'] ?? null;

        if (! is_array($coordinates) || $coordinates === []) {
            return null;
        }

        return [
            'line' => array_map(fn (array $c) => [(float) $c[1], (float) $c[0]], $coordinates), // -> [lat, lon]
            'distance_m' => isset($route['distance']) ? (float) $route['distance'] : null,
            'duration_s' => isset($route['duration']) ? (float) $route['duration'] : null,
        ];
    }

    /**
     * Datos listos para el mapa: paradas numeradas (por su posición real) con coordenadas
     * + geometría del recorrido. Las paradas sin coordenadas se cuentan aparte (`skipped`).
     *
     * @return array{stops: list<array{n: int, name: string, lat: float, lng: float, status: string}>, meta: array|null, skipped: int, vehicle: array|null}
     */
    public function payloadFor(RouteDay $route): array
    {
        $stops = $route->stops()->get()->values()->map(fn (RouteStop $s, int $i) => [
            'n' => $i + 1,
            'name' => $s->customer_name,
            'lat' => $s->latitude !== null ? (float) $s->latitude : null,
            'lng' => $s->longitude !== null ? (float) $s->longitude : null,
            'status' => $s->status->value,
        ]);

        $located = $stops->filter(fn (array $s) => $s['lat'] !== null && $s['lng'] !== null)->values();

        return [
            'stops' => $located->all(),
            'meta' => $this->for($located->map(fn (array $s) => [$s['lat'], $s['lng']])->all()),
            'skipped' => $stops->count() - $located->count(),
            'vehicle' => $this->vehicleFor($route, $located),
        ];
    }

    /**
     * Última posición GPS conocida del camión de la ruta (la manda la APK tracker del chofer)
     * + el trazado por carretera desde ahí hasta la primera parada ("cómo llegar"). Sin
     * límite de antigüedad — se muestra "hace X".
     *
     * @param  Collection<int, array{n: int, name: string, lat: float, lng: float, status: string}>  $located
     * @return array{lat: float, lng: float, recorded_at: string, age: string, accuracy_m: ?float, approach: array|null, next_stop: array{n: int, name: string}|null}|null
     */
    private function vehicleFor(RouteDay $route, $located): ?array
    {
        if ($route->driver_id === null) {
            return null;
        }

        $position = GpsPosition::query()
            ->where(function ($query) use ($route): void {
                $query->where('route_id', $route->id)
                    ->orWhere(fn ($q) => $q->where('driver_id', $route->driver_id)
                        ->whereDate('recorded_at', $route->route_date));
            })
            ->orderByDesc('recorded_at')
            ->first();

        if ($position === null) {
            return null;
        }

        $lat = (float) $position->latitude;
        $lng = (float) $position->longitude;

        // Primera parada a la que va el camión: la primera pendiente con coordenadas
        // (si ya no queda ninguna, la primera de la lista).
        $target = $located->firstWhere('status', 'pending') ?? $located->first();

        $approach = $target
            ? $this->for([[$lat, $lng], [$target['lat'], $target['lng']]])
            : null;

        return [
            'lat' => $lat,
            'lng' => $lng,
            'recorded_at' => $position->recorded_at->toIso8601String(),
            'age' => $position->recorded_at->diffForHumans(),
            'accuracy_m' => $position->accuracy_m !== null ? (float) $position->accuracy_m : null,
            'approach' => $approach,
            'next_stop' => $target ? ['n' => $target['n'], 'name' => $target['name']] : null,
        ];
    }
}
