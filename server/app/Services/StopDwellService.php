<?php

namespace App\Services;

use App\Models\GpsPosition;
use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Models\StopVisit;
use App\Support\Haversine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tiempo de permanencia del camión en cada parada, a partir de las posiciones GPS y una
 * geocerca (radio configurable). No es una máquina de estados en la ingesta: reprocesa el
 * track completo del día y reconstruye las visitas (`stop_visits`) — borra + reinserta por
 * día, así que es idempotente y aguanta lotes de GPS desordenados o de recuperación.
 *
 * Reglas en `config('servalillo.dwell')`.
 */
class StopDwellService
{
    /** Recalcula ayer y hoy (rutas operativas). Lo usa el pase nocturno. */
    public function recomputeRecent(): int
    {
        return $this->recomputeForDate(today()->subDay())
            + $this->recomputeForDate(today());
    }

    /**
     * Recalcula todas las rutas de una fecha. NO se filtra por estado: el pase nocturno corre
     * de madrugada, cuando los días del día anterior ya están "Completada", y son justo esos
     * los que hay que dejar cerrados.
     */
    public function recomputeForDate(Carbon|string $date): int
    {
        $date = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        return (int) RouteDay::query()
            ->whereDate('route_date', $date)
            ->get()
            ->sum(fn (RouteDay $day) => $this->recomputeForRouteDay($day));
    }

    /**
     * Reconstruye las visitas de un día. Protegido con un lock NO bloqueante: si otro proceso
     * (otro poll del tablero, el pase nocturno) lo está haciendo, se sale sin tocar nada.
     *
     * @return int nº de visitas escritas
     */
    public function recomputeForRouteDay(RouteDay $day): int
    {
        $lock = Cache::lock("dwell:routeday:{$day->id}", 15);

        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->run($day);
        } finally {
            $lock->release();
        }
    }

    /**
     * Tramos de trayecto entre paradas consecutivas (por `position`) que tengan visita cerrada:
     * cuánto tardó el camión en llegar de una a la siguiente y a qué velocidad circuló, a partir
     * de `gps_positions.speed_mps` en esa ventana de tiempo. Si una parada intermedia no tiene
     * datos de geocerca (sin visita), se salta y el tramo se calcula hasta la siguiente que sí
     * los tenga — mejor un tramo con los dos nombres reales que ningún dato. No se persiste: se
     * calcula al vuelo a partir de `stop_visits` (ya construidas) + una consulta puntual de GPS.
     *
     * @return list<array{from_stop_id: int, from_name: string, to_stop_id: int, to_name: string, departed_at: Carbon, arrived_at: Carbon, seconds: int, avg_speed_kmh: ?int, max_speed_kmh: ?int}>
     */
    public function transitLegs(RouteDay $day): array
    {
        $stops = $day->stops()->with('visits')->get();

        $legs = [];
        $from = null;

        foreach ($stops as $stop) {
            $arrival = $stop->firstArrivalAt();

            if ($from !== null && $arrival !== null && $arrival->gt($from['departure'])) {
                $legs[] = $this->buildLeg($day, $from['stop'], $stop, $from['departure'], $arrival);
            }

            $departure = $stop->lastDepartureAt();
            if ($departure !== null) {
                $from = ['stop' => $stop, 'departure' => $departure];
            }
        }

        return $legs;
    }

    /** @return array{from_stop_id: int, from_name: string, to_stop_id: int, to_name: string, departed_at: Carbon, arrived_at: Carbon, seconds: int, avg_speed_kmh: ?int, max_speed_kmh: ?int} */
    private function buildLeg(RouteDay $day, RouteStop $from, RouteStop $to, Carbon $departedAt, Carbon $arrivedAt): array
    {
        $speeds = GpsPosition::query()
            ->where(function ($q) use ($day) {
                $q->where('route_id', $day->id);

                if ($day->driver_id !== null) {
                    $q->orWhere(fn ($q2) => $q2->whereNull('route_id')->where('driver_id', $day->driver_id));
                }
            })
            ->whereBetween('recorded_at', [$departedAt, $arrivedAt])
            ->whereNotNull('speed_mps')
            ->pluck('speed_mps')
            ->map(fn ($v) => (float) $v);

        return [
            'from_stop_id' => $from->id,
            'from_name' => $from->customer_name,
            'to_stop_id' => $to->id,
            'to_name' => $to->customer_name,
            'departed_at' => $departedAt,
            'arrived_at' => $arrivedAt,
            'seconds' => $departedAt->diffInSeconds($arrivedAt),
            'avg_speed_kmh' => $speeds->isEmpty() ? null : (int) round($speeds->avg() * 3.6),
            'max_speed_kmh' => $speeds->isEmpty() ? null : (int) round($speeds->max() * 3.6),
        ];
    }

    private function run(RouteDay $day): int
    {
        $stops = $day->stops()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude']);

        $visits = $stops->isEmpty()
            ? collect()
            : $this->buildVisits($day, $stops);

        DB::transaction(function () use ($day, $visits) {
            StopVisit::where('route_id', $day->id)->delete();

            if ($visits->isNotEmpty()) {
                StopVisit::insert($visits->all());
            }
        });

        $day->forceFill(['dwell_recalculated_at' => now()])->saveQuietly();

        return $visits->count();
    }

    /**
     * @param  Collection<int, RouteStop>  $stops
     * @return Collection<int, array<string, mixed>> filas listas para StopVisit::insert()
     */
    private function buildVisits(RouteDay $day, Collection $stops): Collection
    {
        $cfg = config('servalillo.dwell');

        $positions = $this->filterPositions($day, $this->positionsFor($day), $cfg);

        if ($positions->isEmpty()) {
            return collect();
        }

        // Atribuir cada posición a la parada MÁS CERCANA dentro del radio (gana la más cercana:
        // dos geocercas solapadas nunca cuentan la misma posición dos veces).
        $buckets = [];

        foreach ($positions as $p) {
            $nearestId = null;
            $nearestDistance = null;

            foreach ($stops as $stop) {
                $distance = Haversine::meters(
                    (float) $p->latitude, (float) $p->longitude,
                    (float) $stop->latitude, (float) $stop->longitude,
                );

                if ($distance <= $cfg['radius_meters'] && ($nearestDistance === null || $distance < $nearestDistance)) {
                    $nearestDistance = $distance;
                    $nearestId = $stop->id;
                }
            }

            if ($nearestId !== null) {
                $buckets[$nearestId][] = $p->recorded_at;
            }
        }

        $lastFixOverall = $positions->last()->recorded_at;
        $now = now();

        // Si la última posición del día ya es "vieja" (fin de jornada, día pasado), no hay
        // ninguna visita "en curso": las que llegan hasta el último fix se cierran ahí.
        $stale = $lastFixOverall->diffInSeconds($now) > $cfg['merge_gap_seconds'];

        $rows = collect();

        foreach ($buckets as $stopId => $times) {
            foreach ($this->splitIntervals($times, $cfg) as [$enteredAt, $lastInside, $seconds]) {
                $open = ! $stale && $lastInside->equalTo($lastFixOverall);

                if (! $open && $seconds < $cfg['min_seconds']) {
                    continue; // el camión solo pasó cerca, no fue una parada
                }

                $rows->push([
                    'route_stop_id' => $stopId,
                    'route_id' => $day->id,
                    'entered_at' => $enteredAt,
                    'left_at' => $open ? null : $lastInside,
                    'seconds' => $open ? null : $seconds,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Parte una secuencia de fixes (dentro del radio, ordenados asc) en visitas: nueva visita
     * cuando el hueco entre fixes consecutivos supera `merge_gap_seconds` — por debajo de ese
     * umbral se puentea el hueco entero (se asume que el camión siguió ahí sin señal GPS; la
     * pérdida de COBERTURA de red no cuenta como hueco real, ver el comentario de
     * `config('servalillo.dwell')`). Como cada hueco interno queda por construcción
     * `<= merge_gap_seconds`, la duración es simplemente la diferencia entre el primer y el
     * último fix del tramo — no hace falta topar cada hueco por separado.
     *
     * @param  list<Carbon>  $times
     * @return list<array{0: Carbon, 1: Carbon, 2: int}> [entrada, último fix dentro, segundos]
     */
    private function splitIntervals(array $times, array $cfg): array
    {
        $intervals = [];
        $start = $times[0];
        $previous = $times[0];

        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $gap = $times[$i]->getTimestamp() - $previous->getTimestamp();

            if ($gap > $cfg['merge_gap_seconds']) {
                $intervals[] = [$start, $previous, $start->diffInSeconds($previous)];
                $start = $times[$i];
            }

            $previous = $times[$i];
        }

        $intervals[] = [$start, $previous, $start->diffInSeconds($previous)];

        return $intervals;
    }

    /** @return Collection<int, GpsPosition> ordenadas por `recorded_at` */
    private function positionsFor(RouteDay $day): Collection
    {
        $columns = ['latitude', 'longitude', 'accuracy_m', 'speed_mps', 'recorded_at'];

        $tagged = GpsPosition::query()
            ->where('route_id', $day->id)
            ->orderBy('recorded_at')
            ->get($columns);

        if ($tagged->isNotEmpty()) {
            return $tagged;
        }

        if ($day->driver_id === null) {
            return collect();
        }

        // Fallback solo si hay UN único RouteDay de ese chofer y fecha (blindaje: el índice
        // único route_id+route_date ya lo garantiza, pero por si acaso).
        $siblings = RouteDay::query()
            ->where('driver_id', $day->driver_id)
            ->whereDate('route_date', $day->route_date)
            ->count();

        if ($siblings !== 1) {
            return collect();
        }

        $date = $day->route_date instanceof Carbon ? $day->route_date : Carbon::parse($day->route_date);

        return GpsPosition::query()
            ->whereNull('route_id')
            ->where('driver_id', $day->driver_id)
            ->whereBetween('recorded_at', [$date->copy()->startOfDay(), $date->copy()->addDay()->startOfDay()])
            ->orderBy('recorded_at')
            ->get($columns);
    }

    /**
     * @param  Collection<int, GpsPosition>  $positions
     * @return Collection<int, GpsPosition>
     */
    private function filterPositions(RouteDay $day, Collection $positions, array $cfg): Collection
    {
        $baseLat = (float) config('servalillo.base.latitude');
        $baseLng = (float) config('servalillo.base.longitude');

        $shiftStart = null;
        $shiftEnd = null;

        if ($cfg['clamp_to_shift'] && $day->started_at !== null) {
            $shiftStart = $day->started_at->copy()->subMinutes(30);
            $shiftEnd = ($day->completed_at ?? now())->copy()->addMinutes(30);
        }

        return $positions->filter(function (GpsPosition $p) use ($cfg, $baseLat, $baseLng, $shiftStart, $shiftEnd) {
            // Precisión mala: no fiable para geocercas. accuracy_m null (Android lo omite) se acepta.
            if ($p->accuracy_m !== null && (float) $p->accuracy_m > $cfg['accuracy_reject_meters']) {
                return false;
            }

            // Aparcado en la nave (si hay un cliente pegado a la base, no lo contamos como visita).
            if (Haversine::meters((float) $p->latitude, (float) $p->longitude, $baseLat, $baseLng) <= $cfg['exclude_base_radius_meters']) {
                return false;
            }

            // Fuera del horario de jornada (+/- 30 min).
            if ($shiftStart !== null && ($p->recorded_at->lt($shiftStart) || $p->recorded_at->gt($shiftEnd))) {
                return false;
            }

            return true;
        })->values();
    }
}
