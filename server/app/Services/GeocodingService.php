<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bloque 18 (fichaje): buscador de direcciones para <x-ui.geofence-map> — nunca se llama
 * directo desde el navegador. La política de uso de Nominatim (OpenStreetMap) exige un
 * User-Agent que identifique la aplicación, algo que un <script> del navegador no puede fijar
 * de forma fiable; este Service hace de proxy con la cabecera puesta, mismo espíritu que
 * SgraClient/RouteOptimizer hablando con sus APIs externas. Cualquier fallo de red o respuesta
 * inesperada se traga y devuelve una lista vacía — es una comodidad, no un punto crítico: si
 * falla, el pin/radio del mapa se siguen pudiendo ajustar a mano.
 */
class GeocodingService
{
    /** @return list<array{label: string, lat: float, lng: float}> */
    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('app.name').' ('.config('app.url').')',
            ])->timeout(5)->get('https://nominatim.openstreetmap.org/search', [
                'format' => 'json',
                'q' => $query,
                'limit' => 5,
                'countrycodes' => 'es',
                'addressdetails' => 0,
            ]);

            if (! $response->successful() || ! is_array($response->json())) {
                return [];
            }

            return collect($response->json())
                ->filter(fn ($item) => isset($item['lat'], $item['lon'], $item['display_name']))
                ->map(fn ($item) => [
                    'label' => $item['display_name'],
                    'lat' => (float) $item['lat'],
                    'lng' => (float) $item['lon'],
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
