<?php

namespace App\Services;

use App\Enums\RouteStopStatus;
use App\Models\Route;
use App\Models\RouteStop;
use App\Support\Haversine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "Ruta eficiente" (Bloque 13): reordena las paradas PENDIENTES de una ruta para
 * acortar el recorrido. Ancla la primera parada pendiente (no se mueve) y optimiza
 * el resto. Intenta el servicio OSRM /trip (TSP real por carretera); si no responde,
 * usa una heurística local (vecino más cercano + 2-opt sobre distancia haversine).
 *
 * Las paradas ya cerradas (completada/omitida/fallida) conservan su sitio; las
 * pendientes sin coordenadas se dejan al final. Persiste el nuevo `position` (solo
 * las filas que cambian) dentro de una transacción — igual que Board::reorderStops().
 */
class RouteOptimizer
{
    /** @var 'osrm'|'local'|'none' */
    private string $lastMethod = 'none';

    /**
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
    public function optimize(Route $route): array
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

        $optimizedIds = $this->solve($withCoords);

        $coordsById = $withCoords->mapWithKeys(
            fn (RouteStop $s) => [$s->id => [(float) $s->latitude, (float) $s->longitude]],
        );
        $before = Haversine::pathLength($coordsById->values()->all());
        $after = Haversine::pathLength(array_map(fn (int $id) => $coordsById[$id], $optimizedIds));

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
     * Orden óptimo de las paradas con coordenadas (lista de IDs; la primera queda fija).
     *
     * @param  Collection<int, RouteStop>  $stops
     * @return list<int>
     */
    private function solve(Collection $stops): array
    {
        $points = $this->points($stops);
        $ids = $stops->pluck('id')->all();

        if (config('servalillo.routing.enabled')) {
            $order = $this->solveWithOsrm($points);

            if ($order !== null) {
                $this->lastMethod = 'osrm';

                return array_map(fn (int $i) => $ids[$i], $order);
            }
        }

        $this->lastMethod = 'local';

        return array_map(fn (int $i) => $ids[$i], $this->solveLocally($points));
    }

    /**
     * OSRM /trip: TSP por carretera. Devuelve una permutación de índices (0 fijo primero)
     * o null si el servicio no responde / no es utilizable.
     *
     * @param  list<array{0: float, 1: float}>  $points  pares [lat, lon]
     * @return ?list<int>
     */
    private function solveWithOsrm(array $points): ?array
    {
        $coords = implode(';', array_map(
            fn (array $p) => $p[1].','.$p[0], // OSRM quiere lon,lat
            $points,
        ));

        $url = config('servalillo.routing.osrm_url')."/trip/v1/driving/{$coords}";

        try {
            $response = Http::connectTimeout((int) config('servalillo.routing.connect_timeout'))
                ->timeout((int) config('servalillo.routing.timeout'))
                ->acceptJson()
                ->get($url, [
                    'source' => 'first',
                    'roundtrip' => 'true',
                    'overview' => 'false',
                    'annotations' => 'false',
                ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('code') !== 'Ok') {
            return null;
        }

        $waypoints = $response->json('waypoints');

        if (! is_array($waypoints) || count($waypoints) !== count($points)) {
            return null;
        }

        // waypoints[i].waypoint_index = posición óptima del punto de entrada i.
        $order = array_fill(0, count($points), null);

        foreach ($waypoints as $inputIndex => $waypoint) {
            $slot = $waypoint['waypoint_index'] ?? null;

            if (! is_int($slot) || $slot < 0 || $slot >= count($points) || $order[$slot] !== null) {
                return null;
            }

            $order[$slot] = $inputIndex;
        }

        if (in_array(null, $order, true) || $order[0] !== 0) {
            return null;
        }

        return array_values($order);
    }

    /**
     * Heurística local: vecino más cercano desde el índice 0, luego mejora 2-opt.
     * El índice 0 nunca se mueve.
     *
     * @param  list<array{0: float, 1: float}>  $points  pares [lat, lon]
     * @return list<int>
     */
    private function solveLocally(array $points): array
    {
        $n = count($points);
        $remaining = range(1, $n - 1);
        $order = [0];

        while ($remaining !== []) {
            $last = $points[end($order)];
            $best = null;
            $bestDist = INF;

            foreach ($remaining as $key => $idx) {
                $d = Haversine::meters($last[0], $last[1], $points[$idx][0], $points[$idx][1]);

                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $key;
                }
            }

            $order[] = $remaining[$best];
            unset($remaining[$best]);
            $remaining = array_values($remaining);
        }

        return $this->twoOpt($order, $points);
    }

    /**
     * @param  list<int>  $order
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<int>
     */
    private function twoOpt(array $order, array $points): array
    {
        $n = count($order);
        $length = fn (array $o) => Haversine::pathLength(array_map(fn (int $i) => $points[$i], $o));
        $best = $length($order);

        for ($pass = 0; $pass < 20; $pass++) {
            $improved = false;

            for ($i = 1; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $candidate = $order;
                    $segment = array_reverse(array_slice($candidate, $i, $j - $i + 1));
                    array_splice($candidate, $i, $j - $i + 1, $segment);

                    $candidateLength = $length($candidate);

                    if ($candidateLength + 0.01 < $best) {
                        $order = $candidate;
                        $best = $candidateLength;
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

        DB::transaction(function () use ($stopsById, $finalOrder, &$reordered) {
            foreach (array_values($finalOrder) as $index => $id) {
                $stop = $stopsById[$id];
                $newPosition = $index + 1;

                if ($stop->position !== $newPosition) {
                    abort_unless($stop->status === RouteStopStatus::Pending, 422, 'Solo se reordenan paradas pendientes.');
                    $stop->update(['position' => $newPosition]);
                    $reordered++;
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
