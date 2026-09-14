<?php

namespace App\Services;

use App\Models\GpsPosition;
use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Models\StopVisit;
use App\Models\UnplannedStop;
use App\Models\User;
use App\Notifications\UnplannedStopDetected;
use App\Support\Haversine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
        $allSpeeds = GpsPosition::query()
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

        // La MEDIA solo cuenta las muestras en las que el camión circulaba de verdad — si entre
        // dos paradas hubo un tramo parado (una espera, un descanso, una parada real que no
        // coincidió con ninguna geocerca), esos minutos a 0 no deben arrastrar la media hacia
        // abajo y dar una cifra que no se corresponde con cómo circuló. El máximo no hace falta
        // filtrarlo: un pico ya descarta por sí solo cualquier lectura a 0.
        $movingSpeeds = $allSpeeds->filter(fn (float $v) => $v >= (float) config('servalillo.dwell.moving_speed_min_mps'));

        return [
            'from_stop_id' => $from->id,
            'from_name' => $from->customer_name,
            'to_stop_id' => $to->id,
            'to_name' => $to->customer_name,
            'departed_at' => $departedAt,
            'arrived_at' => $arrivedAt,
            'seconds' => $departedAt->diffInSeconds($arrivedAt),
            'avg_speed_kmh' => $movingSpeeds->isEmpty() ? null : (int) round($movingSpeeds->avg() * 3.6),
            'max_speed_kmh' => $allSpeeds->isEmpty() ? null : (int) round($allSpeeds->max() * 3.6),
        ];
    }

    private function run(RouteDay $day): int
    {
        $stops = $day->stops()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude', 'status', 'updated_at']);

        $cfg = config('servalillo.dwell');
        $positions = $this->filterPositions($day, $this->positionsFor($day), $cfg);

        $visits = $stops->isEmpty() || $positions->isEmpty()
            ? collect()
            : $this->buildVisits($day, $stops, $positions, $cfg);

        $unplanned = $this->carryOverNotifications($day, $this->buildUnplannedStops($day, $stops, $positions, $cfg));

        DB::transaction(function () use ($day, $visits, $unplanned) {
            StopVisit::where('route_id', $day->id)->delete();
            if ($visits->isNotEmpty()) {
                StopVisit::insert($visits->all());
            }

            UnplannedStop::where('route_id', $day->id)->delete();
            if ($unplanned->isNotEmpty()) {
                UnplannedStop::insert($unplanned->all());
            }
        });

        $this->notifyNewUnplannedStops($day);

        $day->forceFill(['dwell_recalculated_at' => now()])->saveQuietly();

        return $visits->count();
    }

    /**
     * Conserva `notified_at` entre recálculos, emparejando por `entered_at` — identidad
     * estable de una parada no programada concreta (no cambia aunque su `left_at`/`seconds`
     * sí lo hagan mientras sigue abierta). `run()` borra y reinserta esta tabla entera cada
     * vez (cron nocturno, poll del tablero/chofer/historial); sin este emparejamiento,
     * cada recálculo "olvidaría" que ya se avisó a administración y volvería a notificar la
     * misma parada una y otra vez.
     *
     * @param  Collection<int, array<string, mixed>>  $unplanned
     * @return Collection<int, array<string, mixed>>
     */
    private function carryOverNotifications(RouteDay $day, Collection $unplanned): Collection
    {
        if ($unplanned->isEmpty()) {
            return $unplanned;
        }

        $previouslyNotified = UnplannedStop::where('route_id', $day->id)
            ->whereNotNull('notified_at')
            ->get(['entered_at', 'notified_at'])
            ->keyBy(fn (UnplannedStop $u) => $u->entered_at->toDateTimeString());

        return $unplanned->map(function (array $row) use ($previouslyNotified) {
            $row['notified_at'] = $previouslyNotified->get($row['entered_at']->toDateTimeString())?->notified_at;

            return $row;
        });
    }

    /**
     * Avisa a administración (rol `administrador`, mismo destinatario que
     * `RouteChangeNotifier`) de las paradas no programadas recién descubiertas en este
     * recálculo — `notified_at` sigue `null` tras `carryOverNotifications()` solo para las
     * que son nuevas de verdad. Una notificación por parada.
     */
    private function notifyNewUnplannedStops(RouteDay $day): void
    {
        $fresh = UnplannedStop::where('route_id', $day->id)->whereNull('notified_at')->get();

        if ($fresh->isEmpty()) {
            return;
        }

        $driverName = $day->driver?->user?->name ?? 'Un chofer';
        $recipients = User::role('administrador')->where('is_active', true)->get();

        foreach ($fresh as $stop) {
            Notification::send($recipients, new UnplannedStopDetected($driverName, $day->route_date->toDateString(), $stop->seconds));
        }

        UnplannedStop::whereIn('id', $fresh->pluck('id'))->update(['notified_at' => now()]);
    }

    /**
     * @param  Collection<int, RouteStop>  $stops
     * @param  Collection<int, GpsPosition>  $positions  ya filtradas (precisión, base, horario)
     * @return Collection<int, array<string, mixed>> filas listas para StopVisit::insert()
     */
    private function buildVisits(RouteDay $day, Collection $stops, Collection $positions, array $cfg): Collection
    {
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

    /**
     * Paradas NO programadas: tramos de al menos `unplanned_stop_min_seconds` en los que el
     * camión estuvo parado en un punto que no es ni una parada de la ruta ni la base — p. ej.
     * repostar por libre, un desvío, una avería. Solo lo ve administración/mantenimiento
     * (`RouteGeometry::payloadFor()` lo omite por defecto; solo Board/History piden
     * `includeUnplannedStops: true`) — el chofer nunca lo ve.
     *
     * Mismo criterio de "puentear huecos de señal" que `buildVisits()` (`merge_gap_seconds`),
     * pero el radio de agrupación es más ajustado (`unplanned_stop_radius_meters`): aquí no hay
     * una geocerca ya definida, así que se agrupan fixes consecutivos cercanos al ANCLA del
     * grupo (su primer fix) — nunca al fix anterior.
     *
     * **Bug real de producción corregido (2026-09-14): se marcaban como "no programadas" tramos
     * en los que el camión simplemente circulaba despacio entre dos paradas** (tráfico, calles
     * estrechas, muchos giros). La primera versión comparaba cada fix con el ANTERIOR del grupo:
     * con el camión circulando a poca velocidad, dos fixes consecutivos (~45 s de separación)
     * pueden quedar a menos de `unplanned_stop_radius_meters` el uno del otro sin que el camión
     * haya dejado de moverse ni un segundo — la cadena de saltos cortos nunca superaba el radio
     * fix a fix, aunque el recorrido acumulado sí fuera largo. Doble fix: (1) la distancia se
     * mide siempre contra el ANCLA (primer fix del grupo), no contra el último — un tramo en
     * circulación se va alejando del ancla y el grupo se cierra en cuanto el camión se aleja de
     * verdad, por poco a poco que sea; (2) un fix con `speed_mps` real de circulación
     * (`>= servalillo.dwell.moving_speed_min_mps`, mismo umbral que ya usa `transitLegs()` para
     * "velocidad mientras circulaba") nunca es candidato a parada, directamente.
     *
     * **Segundo bug real de producción corregido (2026-09-14): una parada no programada que
     * ocurría en el mismo sitio que una parada de la ruta YA CERRADA (completada/cancelada/
     * fallida), en otro momento del día, no se detectaba.** La primera versión excluía
     * cualquier fix que cayera dentro del radio de CUALQUIER parada de la ruta, sin mirar la
     * hora ni el estado — la geocerca de una parada "protegía" ese punto durante TODO el día,
     * aunque el camión la hubiera visitado y cerrado horas antes. Si luego el camión volvía a
     * esa misma zona por otro motivo (repostar, un desvío…), ese tramo quedaba invisible para
     * la detección de no programadas. **Fix**: una parada CERRADA (`RouteStopStatus::isClosed()`
     * — completada/cancelada/fallida) solo protege su geocerca hasta el momento en que se
     * cerró (aproximado con `updated_at`, la única marca de tiempo disponible para los 3
     * estados — `completed_at` solo existe para `completed`); pasado ese instante, un fix
     * dentro de su radio vuelve a ser candidato a "no programada". Una parada `Pending` sigue
     * protegiendo su geocerca sin límite de hora (todavía puede visitarse en cualquier momento
     * del día). **`buildVisits()` no se toca**: sigue atribuyendo cualquier fix cercano a la
     * parada más próxima sin mirar la hora — si el camión vuelve horas después a la geocerca de
     * una parada ya cerrada, esa segunda visita se seguirá contando en sus estadísticas de
     * permanencia (dato útil, no se pierde) Y, con este fix, TAMBIÉN puede aparecer como una
     * parada no programada — mejor visibilizar la anomalía dos veces que ocultarla del todo.
     *
     * @param  Collection<int, RouteStop>  $stops
     * @param  Collection<int, GpsPosition>  $positions  ya filtradas (precisión, base, horario)
     * @return Collection<int, array<string, mixed>> filas listas para UnplannedStop::insert()
     */
    private function buildUnplannedStops(RouteDay $day, Collection $stops, Collection $positions, array $cfg): Collection
    {
        if ($positions->isEmpty()) {
            return collect();
        }

        $movingSpeedMin = (float) config('servalillo.dwell.moving_speed_min_mps');

        // Descarta lo que ya cuenta como parada de la ruta (mientras siga protegiendo ese
        // punto — ver el comentario de arriba) y lo que es claramente circulación (velocidad
        // real por encima del umbral) — ninguno de los dos puede ser "no programada".
        $remaining = $positions->reject(function (GpsPosition $p) use ($stops, $cfg, $movingSpeedMin) {
            if ($p->speed_mps !== null && (float) $p->speed_mps >= $movingSpeedMin) {
                return true;
            }

            foreach ($stops as $stop) {
                if ($stop->status->isClosed() && $p->recorded_at->gt($stop->updated_at)) {
                    continue; // cerrada antes de este fix: ya no protege este punto
                }

                $distance = Haversine::meters(
                    (float) $p->latitude, (float) $p->longitude,
                    (float) $stop->latitude, (float) $stop->longitude,
                );

                if ($distance <= $cfg['radius_meters']) {
                    return true;
                }
            }

            return false;
        })->values();

        if ($remaining->isEmpty()) {
            return collect();
        }

        $radius = (float) $cfg['unplanned_stop_radius_meters'];
        $minSeconds = (int) $cfg['unplanned_stop_min_seconds'];
        $lastFixOverall = $positions->last()->recorded_at;
        $now = now();
        $stale = $lastFixOverall->diffInSeconds($now) > $cfg['merge_gap_seconds'];

        $rows = collect();
        $anchor = $remaining->first();
        $cluster = [$anchor];

        for ($i = 1, $n = $remaining->count(); $i < $n; $i++) {
            $point = $remaining[$i];
            $last = $cluster[count($cluster) - 1];

            $gap = $point->recorded_at->getTimestamp() - $last->recorded_at->getTimestamp();
            $distance = Haversine::meters(
                (float) $point->latitude, (float) $point->longitude,
                (float) $anchor->latitude, (float) $anchor->longitude,
            );

            if ($distance <= $radius && $gap <= $cfg['merge_gap_seconds']) {
                $cluster[] = $point;

                continue;
            }

            $this->pushUnplannedCluster($rows, $day, $cluster, $minSeconds, $stale, $lastFixOverall, $now);
            $cluster = [$point];
            $anchor = $point;
        }

        $this->pushUnplannedCluster($rows, $day, $cluster, $minSeconds, $stale, $lastFixOverall, $now);

        return $rows;
    }

    /** @param  list<GpsPosition>  $cluster */
    private function pushUnplannedCluster(Collection $rows, RouteDay $day, array $cluster, int $minSeconds, bool $stale, Carbon $lastFixOverall, Carbon $now): void
    {
        $first = $cluster[0];
        $last = $cluster[count($cluster) - 1];
        $seconds = $first->recorded_at->diffInSeconds($last->recorded_at);
        $open = ! $stale && $last->recorded_at->equalTo($lastFixOverall);

        if (! $open && $seconds < $minSeconds) {
            return; // se movió antes de completar el umbral, o solo pasó por ahí
        }

        $rows->push([
            'route_id' => $day->id,
            'latitude' => round(collect($cluster)->avg(fn (GpsPosition $p) => (float) $p->latitude), 7),
            'longitude' => round(collect($cluster)->avg(fn (GpsPosition $p) => (float) $p->longitude), 7),
            'entered_at' => $first->recorded_at,
            'left_at' => $open ? null : $last->recorded_at,
            'seconds' => $open ? null : $seconds,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
