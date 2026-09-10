<?php

namespace App\Services;

use App\Enums\RouteStatus;
use App\Models\Route;
use App\Models\RouteDay;
use App\Support\RouteCode;
use Illuminate\Support\Carbon;

/**
 * Genera automáticamente el `RouteDay` de cada `Route` vigente para un día. Es idempotente:
 * si ya existe un `RouteDay` para esa ruta ese día (generado antes o creado a mano) no se
 * toca — la edición manual siempre gana. Si una `Route` se borra o se edita después, los
 * `RouteDay` que ya generó no se tocan ni se borran.
 */
class RecurringRouteService
{
    /** @return int RouteDay creados */
    public function generateForDate(Carbon $date): int
    {
        $date = $date->copy()->startOfDay();

        // No se rellenan días pasados.
        if ($date->lt(today())) {
            return 0;
        }

        $created = 0;

        Route::query()
            ->with('truck')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->get()
            ->each(function (Route $route) use ($date, &$created) {
                $routeDay = RouteDay::firstOrCreate(
                    ['route_id' => $route->id, 'route_date' => $date->toDateString()],
                    [
                        'truck_id' => $route->truck_id,
                        'driver_id' => $route->driver_id,
                        'status' => RouteStatus::Published,
                        'service_kind' => $route->service_kind->value,
                        'code' => RouteCode::build($route->service_kind, $date, $route->truck->code),
                        'created_by' => $route->created_by,
                    ],
                );

                if ($routeDay->wasRecentlyCreated) {
                    $created++;
                }
            });

        return $created;
    }

    /** Genera desde hoy hasta `$days` días por delante (para el planificador diario). */
    public function generateHorizon(int $days = 14): int
    {
        $created = 0;
        $date = today();

        for ($i = 0; $i <= $days; $i++) {
            $created += $this->generateForDate($date->copy());
            $date->addDay();
        }

        return $created;
    }

    /** Garantiza que exista el RouteDay de `$route` para `$date` (red de seguridad del tablero). */
    public function ensureForDate(Route $route, Carbon|string $date): RouteDay
    {
        $date = Carbon::parse($date);

        return RouteDay::firstOrCreate(
            ['route_id' => $route->id, 'route_date' => $date->toDateString()],
            [
                'truck_id' => $route->truck_id,
                'driver_id' => $route->driver_id,
                'status' => RouteStatus::Published,
                'service_kind' => $route->service_kind->value,
                'code' => RouteCode::build($route->service_kind, $date, $route->truck?->code ?? $route->truck()->value('code')),
                'created_by' => $route->created_by,
            ],
        );
    }
}
