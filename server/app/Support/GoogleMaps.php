<?php

namespace App\Support;

/**
 * Construye enlaces a Google Maps para que el chofer navegue la ruta. Usa la "Maps URLs API"
 * (gratuita, sin clave): https://developers.google.com/maps/documentation/urls/get-started
 *
 * No se pasa `origin`: Google Maps arranca desde la ubicación GPS del móvil (= el camión).
 */
final class GoogleMaps
{
    /** Máximo de puntos intermedios que acepta la Maps URLs API. */
    public const MAX_WAYPOINTS = 9;

    private const BASE = 'https://www.google.com/maps/dir/?api=1&travelmode=driving';

    /**
     * Direcciones para una ruta entera: destino = última parada, el resto como waypoints
     * (en el orden dado — Google NO los reordena). Si hay más de MAX_WAYPOINTS intermedias,
     * se cortan a las primeras (el chofer navega ese tramo y vuelve a abrir para el resto).
     *
     * @param  list<array{0: float|int, 1: float|int}>  $points  pares [lat, lng] ya ordenados
     */
    public static function directionsUrl(array $points): string
    {
        $points = array_values(array_filter(
            $points,
            fn ($p) => is_array($p) && isset($p[0], $p[1]),
        ));

        if ($points === []) {
            return self::BASE;
        }

        $destination = array_pop($points);
        $waypoints = array_slice($points, 0, self::MAX_WAYPOINTS);

        $url = self::BASE.'&destination='.self::fmt($destination[0], $destination[1]);

        if ($waypoints !== []) {
            $url .= '&waypoints='.implode('%7C', array_map(
                fn (array $p) => self::fmt($p[0], $p[1]),
                $waypoints,
            ));
        }

        return $url;
    }

    /** Direcciones a un único punto (una parada / un cliente). */
    public static function pointUrl(float|int $lat, float|int $lng): string
    {
        return self::BASE.'&destination='.self::fmt($lat, $lng);
    }

    private static function fmt(float|int $lat, float|int $lng): string
    {
        return round((float) $lat, 6).','.round((float) $lng, 6);
    }
}
