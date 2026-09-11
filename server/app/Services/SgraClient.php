<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de la API del dashboard de SGRA (proyecto externo, Raspberry Pi del
 * usuario, Sistema de Gestión de Recursos del Aljibe — nivel de depósitos de agua),
 * alcanzable por Tailscale (`servalillo.sgra.base_url`). Único punto de la app que
 * habla con esa API.
 *
 * La API de SGRA se autentica con sesión por cookie (`POST /api/login`, sin token/API
 * key) y el "depósito activo" es un concepto de sesión, no un parámetro de las rutas
 * de lectura (`GET /api/current`) — para leer TODOS los depósitos hay que, con la
 * MISMA cookie: `PUT /api/tanks/{id}/select` → `GET /api/current`, repetido por
 * depósito. Cada llamada a este cliente abre su propia sesión (cookie nueva), así que
 * no interfiere con la sesión real de nadie viendo el dashboard SGRA en su navegador.
 *
 * Nunca lanza al llamador: cualquier fallo (red, timeout, login rechazado, JSON
 * inesperado) se traga y devuelve `[]` — mismo criterio que
 * App\Services\RouteOptimizer/RouteGeometry hablando con OSRM, para que este panel se
 * degrade con elegancia si el NAS está apagado o sin Tailscale, en vez de romper el
 * resto de la app.
 */
class SgraClient
{
    /**
     * Estado de cada depósito configurado en SGRA.
     *
     * @return list<array{id: string, name: string, fill_pct: float|null, online: bool,
     *                     minutes_ago: int|null, alert_low: bool, color: string}>
     */
    public function tanks(): array
    {
        if (! config('servalillo.sgra.enabled')) {
            return [];
        }

        return Cache::remember(
            'sgra:tanks',
            (int) config('servalillo.sgra.cache_seconds'),
            fn () => $this->fetchTanks(),
        );
    }

    /** @return list<array{id: string, name: string, fill_pct: float|null, online: bool, minutes_ago: int|null, alert_low: bool, color: string}> */
    private function fetchTanks(): array
    {
        $baseUrl = config('servalillo.sgra.base_url');

        try {
            $client = $this->authenticatedClient($baseUrl);

            if ($client === null) {
                return [];
            }

            $tanksResponse = $client->get("{$baseUrl}/api/tanks");

            if (! $tanksResponse->successful()) {
                return [];
            }

            $tanks = $tanksResponse->json('tanks') ?? [];

            return collect($tanks)
                ->filter(fn ($tank) => filled($tank['id'] ?? null))
                ->map(fn ($tank) => $this->resolveTankStatus($client, $baseUrl, $tank))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('SgraClient: no se pudo obtener el estado de los depósitos.', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function authenticatedClient(string $baseUrl): ?PendingRequest
    {
        $client = Http::withOptions(['cookies' => new CookieJar])
            ->connectTimeout((int) config('servalillo.sgra.connect_timeout'))
            ->timeout((int) config('servalillo.sgra.timeout'))
            ->acceptJson();

        $login = $client->post("{$baseUrl}/api/login", [
            'username' => config('servalillo.sgra.username'),
            'password' => config('servalillo.sgra.password'),
        ]);

        return $login->successful() && $login->json('ok') === true ? $client : null;
    }

    /** @return array{id: string, name: string, fill_pct: float|null, online: bool, minutes_ago: int|null, alert_low: bool, color: string} */
    private function resolveTankStatus(PendingRequest $client, string $baseUrl, array $tank): array
    {
        $fallback = [
            'id' => $tank['id'],
            'name' => $tank['name'] ?? $tank['id'],
            'fill_pct' => null,
            'online' => false,
            'minutes_ago' => null,
            'alert_low' => false,
            'color' => $tank['color'] ?? '#2563eb',
        ];

        $select = $client->put("{$baseUrl}/api/tanks/{$tank['id']}/select");

        if (! $select->successful()) {
            return $fallback;
        }

        $current = $client->get("{$baseUrl}/api/current");

        if (! $current->successful() || $current->json('error')) {
            return $fallback;
        }

        return [
            'id' => $current->json('tank_id') ?? $tank['id'],
            'name' => $current->json('tank_name') ?? $fallback['name'],
            'fill_pct' => $current->json('level_pct'),
            'online' => (bool) $current->json('online'),
            'minutes_ago' => $current->json('minutes_ago'),
            'alert_low' => (bool) $current->json('alert_low'),
            'color' => $current->json('tank_color') ?? $fallback['color'],
        ];
    }
}
