<?php

namespace App\Support;

/**
 * Distancia sobre la esfera (fórmula del haversine), en metros. Se usa como
 * estimación de "línea recta" cuando OSRM no está disponible para reordenar
 * las paradas de una ruta (App\Services\RouteOptimizer, Bloque 13).
 */
class Haversine
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    /** Metros entre dos puntos (lat/lon en grados decimales). */
    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Longitud total (metros) de recorrer una secuencia de puntos en orden,
     * sin volver al origen.
     *
     * @param  list<array{0: float, 1: float}>  $points  pares [lat, lon]
     */
    public static function pathLength(array $points): float
    {
        $total = 0.0;

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total += self::meters(
                $points[$i - 1][0], $points[$i - 1][1],
                $points[$i][0], $points[$i][1],
            );
        }

        return $total;
    }
}
