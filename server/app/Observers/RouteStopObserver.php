<?php

namespace App\Observers;

use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Notifications\StopManagedByOffice;

/**
 * Avisa al chofer cuando oficina gestiona una parada de su ruta desde el panel de
 * administración: la añade, la modifica, la quita o la reasigna (Bloque 14).
 *
 * Excepción **deliberada** a "las notificaciones del chofer se disparan con dispatch
 * explícito, nunca observer" (Bloque 12): allí el problema era que un observer no distingue
 * "el chofer marcó fallida" de "oficina editó el estado". Aquí el criterio es inequívoco —
 * el que actúa es oficina (`isManager()`), nunca el chofer — y hay que cubrir **cualquier**
 * camino que toque una parada (tablero, arrastre, "+ Añadir", auto-asignación por fecha,
 * código futuro), cosa que un puñado de dispatch sueltos no garantiza.
 *
 * La generación automática de paradas recurrentes se silencia (`self::$muted`): es sistémica,
 * no "gestión desde el perfil de administración".
 */
class RouteStopObserver
{
    public static bool $muted = false;

    public static function muted(callable $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    public function created(RouteStop $stop): void
    {
        $this->notify($stop->route_id, $stop, 'added');
    }

    public function updated(RouteStop $stop): void
    {
        if ($stop->wasChanged('route_id')) {
            if ($before = $stop->getOriginal('route_id')) {
                $this->notify((int) $before, $stop, 'removed');
            }
            $this->notify($stop->route_id, $stop, 'added');

            return;
        }

        // Un reordenado (solo `position`) no es "gestión de la parada" — no molesta al chofer.
        if (array_diff(array_keys($stop->getChanges()), ['position', 'updated_at']) !== []) {
            $this->notify($stop->route_id, $stop, 'modified');
        }
    }

    public function deleted(RouteStop $stop): void
    {
        $this->notify($stop->route_id, $stop, 'removed');
    }

    private function notify(?int $routeDayId, RouteStop $stop, string $kind): void
    {
        if (self::$muted || ! $routeDayId || ! auth()->check() || ! auth()->user()->isManager()) {
            return;
        }

        $routeDay = RouteDay::with('driver.user')->find($routeDayId);
        $chofer = $routeDay?->driver?->user;

        if (! $chofer || $chofer->id === auth()->id()) {
            return;
        }

        $chofer->notify(new StopManagedByOffice(
            $kind,
            $stop->customer_name,
            $routeDay->route_date->toDateString(),
            auth()->user()->name,
        ));
    }
}
