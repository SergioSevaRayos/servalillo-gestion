<?php

namespace App\Services;

use App\Enums\RouteStopStatus;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\RouteStop;
use App\Observers\RouteStopObserver;
use Illuminate\Support\Carbon;

/**
 * Genera automáticamente la parada de los clientes con calendario por días de la semana
 * (`clients.delivery_weekdays`), con `scheduled_for` = el día que le toca. Si ese día hay una
 * única ruta permanente candidata para su tipo de servicio, nace ya colocada en su columna
 * (`RecurringRouteService::findRouteDayForAutoAssign()`); si no, nace sin ruta (`route_id =
 * null`) en "Sin asignar" para que oficina la coloque a mano. Es idempotente: no crea una
 * segunda parada si el cliente ya tiene una para esa fecha.
 */
class RecurringStopService
{
    /** @return int paradas creadas */
    public function generateForDate(Carbon $date): int
    {
        $date = $date->copy()->startOfDay();

        // No se rellenan días pasados.
        if ($date->lt(today())) {
            return 0;
        }

        // Silencia el aviso al chofer de `RouteStopObserver`: esto es generación sistémica, no
        // "oficina gestionando su parada" — no es lo que pidió notificar el usuario.
        return RouteStopObserver::muted(fn () => $this->run($date));
    }

    private function run(Carbon $date): int
    {
        $weekday = $date->dayOfWeekIso; // 1..7
        $waterTypeId = $this->waterTypeId();
        $created = 0;

        Client::query()
            ->customers()
            ->active()
            ->whereNotNull('delivery_weekdays')
            ->whereJsonContains('delivery_weekdays', $weekday)
            ->get()
            ->each(function (Client $client) use ($date, $waterTypeId, &$created) {
                if (! $client->isScheduledOn($date) || $this->stopExists($client, $date)) {
                    return;
                }

                $routeDay = app(RecurringRouteService::class)
                    ->findRouteDayForAutoAssign($client->service_kind->value, $date);

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

    private function stopExists(Client $client, Carbon $date): bool
    {
        return RouteStop::query()
            ->where('scheduled_for', $date->toDateString())
            ->when(
                $client->tax_id,
                fn ($q) => $q->where('customer_tax_id', $client->tax_id),
                fn ($q) => $q->where('customer_name', $client->name),
            )
            ->exists();
    }

    private function waterTypeId(): ?int
    {
        return DeliveryType::waterId();
    }
}
