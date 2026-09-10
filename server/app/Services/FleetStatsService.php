<?php

namespace App\Services;

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Models\Driver;
use App\Models\RouteStop;
use App\Models\Truck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agrega las métricas del panel estadístico del Administrador (Bloque 5).
 *
 * Todo el cálculo se hace con agregación en la BD (nada de traer filas y contar en PHP,
 * salvo el pivotado final de series pequeñas). Las paradas y rutas usan SoftDeletes, así que
 * cada consulta a través del Query Builder excluye `deleted_at` a mano.
 */
class FleetStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function report(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $operations = $this->operations($fromDate, $toDate);
        $volume = $this->volume($fromDate, $toDate);

        return [
            'range' => ['from' => $fromDate, 'to' => $toDate],
            'kpis' => $this->kpis($fromDate, $toDate, $operations, $volume),
            'operations' => $operations,
            'volume' => $volume,
            'by_driver' => $this->byDriver($fromDate, $toDate),
            'by_truck' => $this->byTruck($fromDate, $toDate),
        ];
    }

    /**
     * @param  array<string, mixed>  $operations
     * @param  array<string, mixed>  $volume
     * @return array<string, mixed>
     */
    private function kpis(string $from, string $to, array $operations, array $volume): array
    {
        $completed = (int) collect($operations['daily'])->sum('completed');
        $failed = (int) collect($operations['daily'])->sum('failed');
        $closed = $completed + $failed;

        return [
            'routes_total' => DB::table('route_days')
                ->whereNull('deleted_at')
                ->whereBetween('route_date', [$from, $to])
                ->count(),
            // Métrica fija de "ahora mismo", no depende del rango elegido.
            'routes_today_operational' => DB::table('route_days')
                ->whereNull('deleted_at')
                ->whereDate('route_date', Carbon::today())
                ->whereIn('status', array_map(fn (RouteStatus $s) => $s->value, RouteStatus::operational()))
                ->count(),
            'stops_completed' => $completed,
            'stops_failed' => $failed,
            'success_rate' => $closed > 0 ? round($completed / $closed * 100, 1) : null,
            'liters_delivered' => $volume['delivered'],
            'fill_rate' => $volume['planned'] > 0 ? round($volume['delivered'] / $volume['planned'] * 100, 1) : null,
            // El backlog "sin asignar" es global, no depende del rango de fechas.
            'unassigned_stops' => RouteStop::query()->unassigned()->count(),
            'active_trucks' => Truck::query()->where('is_active', true)->count(),
            'active_drivers' => Driver::query()->where('is_active', true)->count(),
        ];
    }

    /**
     * Serie diaria de paradas cerradas + desglose de estados de ruta.
     *
     * @return array{daily: list<array{date: string, completed: int, failed: int}>, route_status: array<string, int>}
     */
    private function operations(string $from, string $to): array
    {
        $rows = DB::table('route_stops')
            ->join('route_days', 'route_days.id', '=', 'route_stops.route_id')
            ->whereNull('route_stops.deleted_at')
            ->whereNull('route_days.deleted_at')
            ->whereBetween('route_days.route_date', [$from, $to])
            ->whereIn('route_stops.status', [RouteStopStatus::Completed->value, RouteStopStatus::Failed->value])
            ->groupBy('route_days.route_date', 'route_stops.status')
            ->selectRaw('route_days.route_date::date as day, route_stops.status, count(*) as total')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->day)->toDateString();
            $byDay[$day] ??= ['completed' => 0, 'failed' => 0];
            $byDay[$day][$row->status === RouteStopStatus::Completed->value ? 'completed' : 'failed'] = (int) $row->total;
        }

        $daily = [];
        for ($cursor = Carbon::parse($from); $cursor->lte(Carbon::parse($to)); $cursor->addDay()) {
            $day = $cursor->toDateString();
            $daily[] = [
                'date' => $day,
                'completed' => $byDay[$day]['completed'] ?? 0,
                'failed' => $byDay[$day]['failed'] ?? 0,
            ];
        }

        $routeStatus = DB::table('route_days')
            ->whereNull('deleted_at')
            ->whereBetween('route_date', [$from, $to])
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->all();

        // Normaliza a todos los casos del enum, en orden, para que el donut sea estable.
        $ordered = [];
        foreach (RouteStatus::cases() as $case) {
            $ordered[$case->value] = (int) ($routeStatus[$case->value] ?? 0);
        }

        return ['daily' => $daily, 'route_status' => $ordered];
    }

    /**
     * Litros planificados vs entregados (paradas cerradas) y desglose por tipo de reparto.
     *
     * @return array{planned: float, delivered: float, by_type: list<array{type: string, planned: float, delivered: float}>}
     */
    private function volume(string $from, string $to): array
    {
        $base = DB::table('route_stops')
            ->join('route_days', 'route_days.id', '=', 'route_stops.route_id')
            ->whereNull('route_stops.deleted_at')
            ->whereNull('route_days.deleted_at')
            ->whereBetween('route_days.route_date', [$from, $to]);

        $plannedExpr = "coalesce(sum(route_stops.planned_quantity) filter (where route_stops.status in ('completed', 'failed')), 0)";
        $deliveredExpr = "coalesce(sum(route_stops.delivered_quantity) filter (where route_stops.status = 'completed'), 0)";

        $totals = (clone $base)
            ->selectRaw("$plannedExpr as planned, $deliveredExpr as delivered")
            ->first();

        $byType = (clone $base)
            ->leftJoin('delivery_types', 'delivery_types.id', '=', 'route_stops.delivery_type_id')
            ->groupBy('delivery_types.id', 'delivery_types.name')
            ->selectRaw("coalesce(delivery_types.name, 'Sin tipo') as type")
            ->selectRaw("$plannedExpr as planned, $deliveredExpr as delivered")
            ->havingRaw("$plannedExpr > 0 or $deliveredExpr > 0")
            ->orderByRaw("$deliveredExpr desc")
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'planned' => (float) $row->planned,
                'delivered' => (float) $row->delivered,
            ])
            ->all();

        return [
            'planned' => (float) ($totals->planned ?? 0),
            'delivered' => (float) ($totals->delivered ?? 0),
            'by_type' => $byType,
        ];
    }

    /**
     * @return list<array{driver: string, completed: int, failed: int, failure_rate: float|null, liters: float}>
     */
    private function byDriver(string $from, string $to): array
    {
        return DB::table('route_stops')
            ->join('route_days', 'route_days.id', '=', 'route_stops.route_id')
            ->join('drivers', 'drivers.id', '=', 'route_days.driver_id')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->whereNull('route_stops.deleted_at')
            ->whereNull('route_days.deleted_at')
            ->whereBetween('route_days.route_date', [$from, $to])
            ->whereIn('route_stops.status', [RouteStopStatus::Completed->value, RouteStopStatus::Failed->value])
            ->groupBy('users.name')
            ->selectRaw('users.name as driver')
            ->selectRaw("count(*) filter (where route_stops.status = 'completed') as completed")
            ->selectRaw("count(*) filter (where route_stops.status = 'failed') as failed")
            ->selectRaw("coalesce(sum(route_stops.delivered_quantity) filter (where route_stops.status = 'completed'), 0) as liters")
            ->orderByDesc('completed')
            ->orderBy('driver')
            ->get()
            ->map(function ($row) {
                $closed = (int) $row->completed + (int) $row->failed;

                return [
                    'driver' => $row->driver,
                    'completed' => (int) $row->completed,
                    'failed' => (int) $row->failed,
                    'failure_rate' => $closed > 0 ? round((int) $row->failed / $closed * 100, 1) : null,
                    'liters' => (float) $row->liters,
                ];
            })
            ->all();
    }

    /**
     * @return list<array{truck: string, capacity: int|null, route_days: int, km: int, liters: float}>
     */
    private function byTruck(string $from, string $to): array
    {
        // km por ruta: solo las rutas que tienen las DOS lecturas (inicio y fin).
        $kmByTruck = DB::table('odometer_readings')
            ->join('route_days', 'route_days.id', '=', 'odometer_readings.route_id')
            ->whereNull('route_days.deleted_at')
            ->whereBetween('route_days.route_date', [$from, $to])
            ->groupBy('odometer_readings.route_id', 'route_days.truck_id')
            ->havingRaw('count(*) = 2')
            ->selectRaw('route_days.truck_id, max(odometer_readings.value) - min(odometer_readings.value) as km')
            ->get()
            ->groupBy('truck_id')
            ->map(fn ($rows) => (int) $rows->sum('km'));

        $routeDaysByTruck = DB::table('route_days')
            ->whereNull('deleted_at')
            ->whereBetween('route_date', [$from, $to])
            ->groupBy('truck_id')
            ->selectRaw('truck_id, count(distinct route_date) as route_days')
            ->pluck('route_days', 'truck_id');

        $litersByTruck = DB::table('route_stops')
            ->join('route_days', 'route_days.id', '=', 'route_stops.route_id')
            ->whereNull('route_stops.deleted_at')
            ->whereNull('route_days.deleted_at')
            ->whereBetween('route_days.route_date', [$from, $to])
            ->where('route_stops.status', RouteStopStatus::Completed->value)
            ->groupBy('route_days.truck_id')
            ->selectRaw('route_days.truck_id, coalesce(sum(route_stops.delivered_quantity), 0) as liters')
            ->pluck('liters', 'truck_id');

        return Truck::query()
            ->orderBy('code')
            ->get(['id', 'code', 'capacity_liters'])
            ->map(fn (Truck $truck) => [
                'truck' => $truck->code,
                'capacity' => $truck->capacity_liters,
                'route_days' => (int) ($routeDaysByTruck[$truck->id] ?? 0),
                'km' => (int) ($kmByTruck[$truck->id] ?? 0),
                'liters' => (float) ($litersByTruck[$truck->id] ?? 0),
            ])
            ->filter(fn (array $row) => $row['route_days'] > 0 || $row['km'] > 0 || $row['liters'] > 0)
            ->values()
            ->all();
    }
}
