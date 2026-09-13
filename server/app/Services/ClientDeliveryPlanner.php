<?php

namespace App\Services;

use App\Enums\RouteStopStatus;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Support\Carbon;

/**
 * "Planificar reparto" ampliado (2026-09-13, petición del usuario): genera de una vez un
 * lote de `RouteStop` para un cliente, en el rango de fechas y días de la semana elegidos a
 * mano desde el botón "Planificar reparto" (fila de `/clientes` o ficha del cliente) —
 * pensado para agilizar la gestión de "Viajes", donde con varias rutas candidatas el
 * generador nocturno (`RecurringStopService`) no puede adivinar sola cuál usar.
 *
 * Decisión explícita del usuario: esto NO es un calendario recurrente permanente como
 * `clients.delivery_weekdays` (eso ya existe, lo gestiona `RecurringStopService`/
 * `RecurringRouteService` y sigue generando solo cada noche) — es un lote fijo, de una sola
 * vez, para el rango pedido. No toca ningún campo del cliente. Si se quiere más allá del
 * rango, se vuelve a planificar.
 *
 * `$route = null` dejan las paradas en "Sin asignar" (mismo comportamiento que el botón de
 * siempre); con una ruta elegida, se colocan directamente en su columna del tablero
 * (`RouteDay` de esa ruta y esa fecha, vía `RecurringRouteService::ensureForDate()` — se crea
 * si falta, se reabre si ya estaba completada).
 */
class ClientDeliveryPlanner
{
    public function __construct(private readonly RecurringRouteService $routes) {}

    /**
     * @param  list<int>  $weekdays  días ISO (1 lunes .. 7 domingo) en los que se planifica
     * @return int paradas creadas
     */
    public function plan(Client $client, ?Route $route, Carbon $from, Carbon $to, array $weekdays): int
    {
        $waterTypeId = DeliveryType::waterId();
        $created = 0;

        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            if (! in_array($date->dayOfWeekIso, $weekdays, true) || $this->stopExists($client, $date)) {
                continue;
            }

            $routeDay = $route ? $this->routes->ensureForDate($route, $date) : null;
            $routeDay?->reopenIfCompleted();

            RouteStop::create([
                'route_id' => $routeDay?->id,
                'position' => (RouteStop::where('route_id', $routeDay?->id)->max('position') ?? 0) + 1,
                'scheduled_for' => $date->toDateString(),
                'service_kind' => $client->service_kind->value,
                'customer_name' => $client->name,
                'customer_tax_id' => $client->tax_id,
                'address' => $client->address,
                'latitude' => $client->latitude,
                'longitude' => $client->longitude,
                'contact_name' => $client->contact_name,
                'contact_phone' => $client->phone,
                'delivery_type_id' => $waterTypeId,
                'status' => RouteStopStatus::Pending,
                'planned_quantity' => $client->typical_quantity,
                'data' => [],
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Mismo criterio que `RecurringStopService::stopExists()` — no duplicar si el cliente ya
     * tiene una parada ese día, incluidas las borradas (una decisión manual de oficina manda).
     */
    private function stopExists(Client $client, Carbon $date): bool
    {
        return RouteStop::withTrashed()
            ->where('scheduled_for', $date->toDateString())
            ->when(
                $client->tax_id,
                fn ($q) => $q->where('customer_tax_id', $client->tax_id),
                fn ($q) => $q->where('customer_name', $client->name),
            )
            ->exists();
    }
}
