<?php

namespace App\Services;

use App\Enums\RouteStopStatus;
use App\Models\Client;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Suspender todos los repartos" (2026-09-14, petición del usuario): cuando un cliente con un
 * calendario recurrente activo (`delivery_weekdays`/`frequency_days`) ya no quiere el servicio,
 * antes había que borrar del tablero cada `RouteStop` pendiente una a una y encima el calendario
 * seguía generando paradas nuevas cada noche. Este servicio hace las dos cosas de una vez:
 * apaga el calendario del cliente (para que `RecurringStopService` deje de generar) y cancela
 * de golpe todas las paradas pendientes ya creadas (hoy incluido), emparejadas por CIF/nombre
 * igual que `Client::pastStops()`/`ClientDeliveryPlanner::stopExists()`.
 *
 * Solo toca paradas `Pending`: las completadas/falladas/canceladas son historial y no se tocan.
 * El borrado es un `->delete()` por modelo (no un `whereIn(...)->delete()` de query builder) para
 * que `RouteStopObserver` dispare igual que al borrar una parada a mano desde el tablero.
 */
class ClientDeliverySuspender
{
    /** @return int paradas pendientes canceladas */
    public function suspend(Client $client): int
    {
        $cancelled = 0;

        $this->pendingStopsQuery($client)->get()->each(function (RouteStop $stop) use (&$cancelled) {
            $stop->delete();
            $cancelled++;
        });

        $client->update([
            'delivery_weekdays' => null,
            'frequency_days' => null,
            'schedule_starts_on' => null,
            'schedule_ends_on' => null,
        ]);

        return $cancelled;
    }

    public function pendingStopsCount(Client $client): int
    {
        return $this->pendingStopsQuery($client)->count();
    }

    private function pendingStopsQuery(Client $client): Builder
    {
        return RouteStop::query()
            ->where('status', RouteStopStatus::Pending)
            ->when(
                $client->tax_id,
                fn ($q) => $q->where('customer_tax_id', $client->tax_id),
                fn ($q) => $q->where('customer_name', $client->name),
            );
    }
}
