<?php

namespace App\Services;

use App\Enums\RouteStatus;
use App\Enums\ServiceKind;
use App\Models\Route;
use App\Models\TruckAssignment;
use App\Support\RouteCode;
use Illuminate\Support\Carbon;

/**
 * Genera automáticamente la `Route` del día para cada asignación camión↔chofer vigente
 * (`truck_assignments`, `valid_from`..`valid_until` o sin fin). Es idempotente: si ya existe una
 * ruta para ese camión ese día (creada a mano o por una generación anterior) no se toca — la
 * edición manual siempre gana. Si una asignación se borra o se edita después, las rutas que ya
 * generó no se tocan ni se borran (mismo criterio que `RecurringStopService` con las paradas).
 */
class RecurringRouteService
{
    /** @return int rutas creadas */
    public function generateForDate(Carbon $date): int
    {
        $date = $date->copy()->startOfDay();

        // No se rellenan días pasados.
        if ($date->lt(today())) {
            return 0;
        }

        $created = 0;

        TruckAssignment::query()
            ->with('truck')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->get()
            ->each(function (TruckAssignment $assignment) use ($date, &$created) {
                $route = Route::firstOrCreate(
                    ['truck_id' => $assignment->truck_id, 'route_date' => $date->toDateString()],
                    [
                        'driver_id' => $assignment->driver_id,
                        'status' => RouteStatus::Published,
                        'service_kind' => ServiceKind::Reparto->value,
                        'code' => RouteCode::build(ServiceKind::Reparto, $date, $assignment->truck->code),
                        'created_by' => $assignment->created_by,
                    ],
                );

                if ($route->wasRecentlyCreated) {
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
}
