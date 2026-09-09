<?php

namespace App\Services;

use App\Enums\RouteStopStatus;
use App\Models\GpsPosition;
use App\Models\Route;
use App\Models\RouteStop;
use App\Support\Haversine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "Ruta eficiente" (Bloque 13): reordena las paradas PENDIENTES de una ruta para
 * acortar el recorrido (camino abierto: vecino más cercano probando cada inicio +
 * mejora 2-opt).
 *
 * - El punto de partida (`$origin`) lo elige quien llama: la base de la flota o una
 *   parada concreta ("¿Desde la base o desde un cliente?"). La primera pendiente pasa
 *   a ser la más cercana a ese punto.
 * - Si no se pasa origen, se toma la **última parada cerrada** con coordenadas (donde
 *   está el camión); si tampoco hay, la optimización es **libre** (se busca el orden
 *   más corto, aunque cambie qué parada va primera).
 *
 * La matriz de distancias es la real por carretera (OSRM /table); si OSRM no responde,
 * se usa la distancia en línea recta (haversine). Nunca deja la ruta peor que como
 * estaba: si el orden actual ya es igual o mejor, no se toca.
 *
 * Las paradas cerradas conservan su sitio (su `position` solo se compacta); las
 * pendientes sin coordenadas se dejan al final. Persiste el nuevo `position` (solo las
 * filas que cambian) dentro de una transacción — igual que Board::reorderStops().
 */
class RouteOptimizer
{
    /** @var 'osrm'|'local'|'none' */
    private string $lastMethod = 'none';

    /** Coordenadas [lat, lon] de la base de la flota (config). */
    public function baseOrigin(): array
    {
        return [
            (float) config('servalillo.base.latitude'),
            (float) config('servalillo.base.longitude'),
        ];
    }

    /**
     * Última posición GPS conocida del camión de esta ruta (la manda la APK tracker del
     * chofer), si es lo bastante reciente para usarla como punto de salida.
     *
     * @return array{0: float, 1: float}|null
     */
    public function latestVehiclePosition(Route $route, int $maxAgeMinutes = 60): ?array
    {
        $position = $this->latestVehicleGpsPosition($route, $maxAgeMinutes);

        return $position
            ? [(float) $position->latitude, (float) $position->longitude]
            : null;
    }

    /** Antigüedad legible ("hace 3 min") de la última posición del camión, o null si no sirve. */
    public function vehiclePositionAge(Route $route, int $maxAgeMinutes = 60): ?string
    {
        return $this->latestVehicleGpsPosition($route, $maxAgeMinutes)?->recorded_at?->diffForHumans();
    }

    private function latestVehicleGpsPosition(Route $route, int $maxAgeMinutes): ?GpsPosition
    {
        if ($route->driver_id === null) {
            return null;
        }

        $cutoff = Carbon::now()->subMinutes($maxAgeMinutes);

        return GpsPosition::query()
            ->where('recorded_at', '>=', $cutoff)
            ->where(function ($query) use ($route): void {
                $query->where('route_id', $route->id)
                    ->orWhere(fn ($q) => $q->where('driver_id', $route->driver_id)
                        ->whereDate('recorded_at', $route->route_date));
            })
            ->orderByDesc('recorded_at')
            ->first();
    }

    /**
     * @param  array{0: float, 1: float}|null  $origin  punto de salida elegido (base o una
     *                                                  parada); null = autodetectar
     * @return array{
     *     moved: bool,
     *     method: 'osrm'|'local'|'none',
     *     reordered: int,
     *     optimizable: int,
     *     skipped_no_coords: int,
     *     distance_before_m: ?float,
     *     distance_after_m: ?float,
     * }
     */
    public function optimize(Route $route, ?array $origin = null): array
    {
        $this->lastMethod = 'none';

        $stops = $route->stops()->get();
        $pending = $stops->where('status', RouteStopStatus::Pending)->values();

        [$withCoords, $noCoords] = $pending->partition(
            fn (RouteStop $s) => $s->latitude !== null && $s->longitude !== null,
        );
        $withCoords = $withCoords->values();
        $noCoords = $noCoords->values();

        if ($withCoords->count() < 2) {
            return $this->result(false, 0, $withCoords->count(), $noCoords->count(), null, null);
        }

        // Sin origen explícito: la última parada cerrada con coordenadas (donde está el
        // camión). Si tampoco hay, la optimización es libre.
        if ($origin === null) {
            $lastClosed = $stops->last(fn (RouteStop $s) => $s->status !== RouteStopStatus::Pending
                && $s->latitude !== null && $s->longitude !== null);
            $origin = $lastClosed
                ? [(float) $lastClosed->latitude, (float) $lastClosed->longitude]
                : null;
        }

        $optimizedIds = $this->solve($withCoords, $origin);

        $coordsById = $withCoords->mapWithKeys(
            fn (RouteStop $s) => [$s->id => [(float) $s->latitude, (float) $s->longitude]],
        );
        $currentPath = $coordsById->values()->all();
        $optimizedPath = array_map(fn (int $id) => $coordsById[$id], $optimizedIds);
        if ($origin !== null) {
            array_unshift($currentPath, $origin);
            array_unshift($optimizedPath, $origin);
        }
        $before = Haversine::pathLength($currentPath);
        $after = Haversine::pathLength($optimizedPath);

        $queue = [...$optimizedIds, ...$noCoords->pluck('id')->all()];

        // Reconstruye el orden global: cada slot de una parada pendiente se rellena con el
        // siguiente id optimizado; las cerradas conservan su id (y por tanto su sitio).
        $finalOrder = [];
        foreach ($stops as $stop) {
            $finalOrder[] = $stop->status === RouteStopStatus::Pending
                ? array_shift($queue)
                : $stop->id;
        }

        $reordered = $this->persist($stops->keyBy('id'), $finalOrder);

        return $this->result($reordered > 0, $reordered, $withCoords->count(), $noCoords->count(), $before, $after);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{message: string, variant: string}
     */
    public function toast(array $result): array
    {
        if ($result['optimizable'] < 2) {
            return [
                'message' => 'No hay suficientes paradas con ubicación para calcular la ruta.',
                'variant' => 'warning',
            ];
        }

        if (! $result['moved']) {
            return ['message' => 'La ruta ya estaba en el orden más eficiente.', 'variant' => 'success'];
        }

        $message = "Ruta reordenada: {$result['reordered']} paradas.";

        if ($result['distance_before_m'] !== null
            && $result['distance_after_m'] !== null
            && $result['distance_before_m'] - $result['distance_after_m'] >= 500) {
            $km = round(($result['distance_before_m'] - $result['distance_after_m']) / 1000);
            $message .= " ~{$km} km menos.";
        }

        if ($result['skipped_no_coords'] > 0) {
            $n = $result['skipped_no_coords'];
            $message .= $n === 1
                ? ' 1 parada sin ubicación queda al final.'
                : " {$n} paradas sin ubicación quedan al final.";
        }

        if ($result['method'] === 'local') {
            $message .= ' (Estimación local: el servicio de rutas no respondió.)';
        }

        return ['message' => $message, 'variant' => $result['method'] === 'local' ? 'warning' : 'success'];
    }

    /**
     * Orden óptimo de las paradas con coordenadas (lista de IDs). Nunca peor que el actual.
     *
     * @param  Collection<int, RouteStop>  $stops
     * @param  array{0: float, 1: float}|null  $origin  punto fijo del que sale el recorrido (última
     *                                                  parada cerrada), o null para optimización libre
     * @return list<int>
     */
    private function solve(Collection $stops, ?array $origin): array
    {
        $ids = $stops->pluck('id')->all();
        $n = count($ids);

        // Índices: si hay origen va como 0 (fijo) y las paradas pasan a 1..n; si no, 0..n-1.
        $allPoints = $origin !== null ? [$origin, ...$this->points($stops)] : $this->points($stops);
        $offset = $origin !== null ? 1 : 0;

        $matrix = config('servalillo.routing.enabled') ? $this->osrmTable($allPoints) : null;
        $this->lastMethod = $matrix !== null ? 'osrm' : 'local';

        $dist = $matrix !== null
            ? fn (int $i, int $j): float => (float) $matrix[$i][$j]
            : fn (int $i, int $j): float => Haversine::meters($allPoints[$i][0], $allPoints[$i][1], $allPoints[$j][0], $allPoints[$j][1]);

        $cost = function (array $order) use ($dist): float {
            $sum = 0.0;
            for ($k = 1, $len = count($order); $k < $len; $k++) {
                $sum += $dist($order[$k - 1], $order[$k]);
            }

            return $sum;
        };

        // Orden actual (con el origen delante si lo hay).
        $current = range(0, $n + $offset - 1);
        $best = $current;
        $bestCost = $cost($current);

        if ($origin !== null) {
            // El origen (índice 0) queda fijo; se optimiza el resto.
            $candidate = $this->twoOpt($this->nearestNeighbourFrom(0, $n + 1, $dist), $cost);

            if ($cost($candidate) + 1.0 < $bestCost) {
                $best = $candidate;
                $bestCost = $cost($candidate);
            }
        } else {
            // Libre: se prueba cada inicio y se elige el camino abierto más corto.
            for ($start = 0; $start < $n; $start++) {
                $candidate = $this->twoOpt($this->nearestNeighbourFrom($start, $n, $dist), $cost);

                if ($cost($candidate) + 1.0 < $bestCost) {
                    $best = $candidate;
                    $bestCost = $cost($candidate);
                }
            }
        }

        // Quitar el origen y devolver los ids de parada en el nuevo orden.
        $pendingOrder = $origin !== null ? array_slice($best, 1) : $best;

        return array_map(fn (int $i) => $ids[$i - $offset], $pendingOrder);
    }

    /**
     * Matriz N×N de distancias reales por carretera (metros) vía OSRM /table; null si el
     * servicio no responde o algún par de puntos no es ruteable.
     *
     * @param  list<array{0: float, 1: float}>  $points  pares [lat, lon]
     * @return list<list<float>>|null
     */
    private function osrmTable(array $points): ?array
    {
        $n = count($points);
        $coords = implode(';', array_map(fn (array $p) => $p[1].','.$p[0], $points)); // OSRM: lon,lat

        try {
            $response = Http::connectTimeout((int) config('servalillo.routing.connect_timeout'))
                ->timeout((int) config('servalillo.routing.timeout'))
                ->acceptJson()
                ->get(config('servalillo.routing.osrm_url')."/table/v1/driving/{$coords}", ['annotations' => 'distance']);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('code') !== 'Ok') {
            return null;
        }

        $distances = $response->json('distances');

        if (! is_array($distances) || count($distances) !== $n) {
            return null;
        }

        foreach ($distances as $row) {
            if (! is_array($row) || count($row) !== $n || in_array(null, $row, true)) {
                return null;
            }
        }

        return $distances;
    }

    /**
     * Vecino más cercano empezando por el índice `$start` (que queda fijo el primero).
     *
     * @return list<int>
     */
    private function nearestNeighbourFrom(int $start, int $n, callable $dist): array
    {
        $remaining = array_values(array_diff(range(0, $n - 1), [$start]));
        $order = [$start];

        while ($remaining !== []) {
            $last = end($order);
            $bestKey = 0;
            $bestDist = INF;

            foreach ($remaining as $key => $idx) {
                $d = $dist($last, $idx);

                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestKey = $key;
                }
            }

            $order[] = $remaining[$bestKey];
            unset($remaining[$bestKey]);
            $remaining = array_values($remaining);
        }

        return $order;
    }

    /**
     * Mejora 2-opt: invierte segmentos mientras acorten el camino. El índice 0 no se mueve.
     *
     * @param  list<int>  $order
     * @return list<int>
     */
    private function twoOpt(array $order, callable $cost): array
    {
        $n = count($order);
        $best = $cost($order);

        for ($pass = 0; $pass < 20; $pass++) {
            $improved = false;

            for ($i = 1; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $candidate = $order;
                    $segment = array_reverse(array_slice($candidate, $i, $j - $i + 1));
                    array_splice($candidate, $i, $j - $i + 1, $segment);

                    $candidateCost = $cost($candidate);

                    if ($candidateCost + 0.01 < $best) {
                        $order = $candidate;
                        $best = $candidateCost;
                        $improved = true;
                    }
                }
            }

            if (! $improved) {
                break;
            }
        }

        return $order;
    }

    /**
     * @param  Collection<int, RouteStop>  $stopsById
     * @param  list<int>  $finalOrder
     */
    private function persist(Collection $stopsById, array $finalOrder): int
    {
        $reordered = 0;

        // $finalOrder mantiene cada parada cerrada en su hueco estructural, así que su `position`
        // solo puede moverse para compactar la numeración (huecos por paradas borradas) — eso es
        // inofensivo. Solo cuentan como "reordenadas" las pendientes.
        DB::transaction(function () use ($stopsById, $finalOrder, &$reordered) {
            foreach (array_values($finalOrder) as $index => $id) {
                $stop = $stopsById[$id];
                $newPosition = $index + 1;

                if ($stop->position !== $newPosition) {
                    $stop->update(['position' => $newPosition]);

                    if ($stop->status === RouteStopStatus::Pending) {
                        $reordered++;
                    }
                }
            }
        });

        return $reordered;
    }

    /**
     * @param  Collection<int, RouteStop>  $stops
     * @return list<array{0: float, 1: float}> pares [lat, lon]
     */
    private function points(Collection $stops): array
    {
        return $stops->map(fn (RouteStop $s) => [(float) $s->latitude, (float) $s->longitude])->all();
    }

    /**
     * @return array{moved: bool, method: 'osrm'|'local'|'none', reordered: int, optimizable: int, skipped_no_coords: int, distance_before_m: ?float, distance_after_m: ?float}
     */
    private function result(bool $moved, int $reordered, int $optimizable, int $skipped, ?float $before, ?float $after): array
    {
        return [
            'moved' => $moved,
            'method' => $this->lastMethod,
            'reordered' => $reordered,
            'optimizable' => $optimizable,
            'skipped_no_coords' => $skipped,
            'distance_before_m' => $before,
            'distance_after_m' => $after,
        ];
    }
}
