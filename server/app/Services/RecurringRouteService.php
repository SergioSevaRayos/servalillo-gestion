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

    /**
     * Busca la única ruta permanente candidata (mismo tipo de servicio, vigente esa fecha) para
     * colocar ahí, sola, una parada que todavía no tiene ruta — reprogramada, con fecha puesta a
     * mano, o recurrente de un cliente. Con cero o varias candidatas no hay forma de adivinar
     * cuál, y devuelve null (la parada se queda en "Sin asignar" para que oficina la coloque).
     * Si la hay, garantiza su RouteDay para esa fecha (lo crea si falta) y lo reabre si ya
     * estaba con la jornada terminada.
     */
    public function findRouteDayForAutoAssign(string $serviceKind, Carbon|string $date): ?RouteDay
    {
        $date = Carbon::parse($date);

        $candidates = Route::query()
            ->where('service_kind', $serviceKind)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->get();

        if ($candidates->count() !== 1) {
            return null;
        }

        $routeDay = $this->ensureForDate($candidates->first(), $date);
        $routeDay->reopenIfCompleted();

        return $routeDay;
    }
}
