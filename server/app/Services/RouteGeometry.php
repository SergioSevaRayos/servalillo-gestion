<?php

namespace App\Services;

use App\Models\Route;
use App\Models\RouteStop;
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
     * @return array{stops: list<array{n: int, name: string, lat: float, lng: float, status: string}>, meta: array|null, skipped: int}
     */
    public function payloadFor(Route $route): array
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
        ];
    }
}
