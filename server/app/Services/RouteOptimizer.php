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
 * el orden del resto como un camino abierto (vecino más cercano + mejora 2-opt).
 *
 * La matriz de distancias es la real por carretera (OSRM /table); si OSRM no
 * responde, se usa la distancia en línea recta (haversine). Nunca deja la ruta peor
 * que como estaba: si el orden actual ya es igual o mejor, no se toca.
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
     * Nunca devuelve un orden peor que el actual.
     *
     * @param  Collection<int, RouteStop>  $stops
     * @return list<int>
     */
    private function solve(Collection $stops): array
    {
        $points = $this->points($stops);
        $ids = $stops->pluck('id')->all();
        $n = count($points);

        $matrix = config('servalillo.routing.enabled') ? $this->osrmTable($points) : null;
        $this->lastMethod = $matrix !== null ? 'osrm' : 'local';

        $dist = $matrix !== null
            ? fn (int $i, int $j): float => (float) $matrix[$i][$j]
            : fn (int $i, int $j): float => Haversine::meters($points[$i][0], $points[$i][1], $points[$j][0], $points[$j][1]);

        $cost = function (array $order) use ($dist): float {
            $sum = 0.0;
            for ($k = 1, $len = count($order); $k < $len; $k++) {
                $sum += $dist($order[$k - 1], $order[$k]);
            }

            return $sum;
        };

        $solved = $this->twoOpt($this->nearestNeighbour($n, $dist), $cost);
        $current = range(0, $n - 1);

        // Nunca empeorar: si el orden actual ya es igual o mejor, se deja como está.
        $order = $cost($solved) + 1.0 < $cost($current) ? $solved : $current;

        return array_map(fn (int $i) => $ids[$i], $order);
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
     * Vecino más cercano desde el índice 0 (que nunca se mueve).
     *
     * @return list<int>
     */
    private function nearestNeighbour(int $n, callable $dist): array
    {
        $remaining = range(1, $n - 1);
        $order = [0];

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
