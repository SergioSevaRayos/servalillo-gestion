<?php

namespace App\Support\Notifications;

use App\Models\Route;
use App\Models\RouteStop;
use App\Models\User;
use App\Notifications\ChoferRouteChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Traduce las desviaciones del plan que hace un chofer en su ruta a notificaciones
 * para los administradores (Bloque 12). Se llama explícitamente desde los puntos de
 * mutación de la operativa del chofer; nunca desde el tablero de oficina ni en consola.
 */
class RouteChangeNotifier
{
    public function stopFailed(RouteStop $stop): void
    {
        $this->sendForStop('stop_failed', $stop);
    }

    public function stopSkipped(RouteStop $stop): void
    {
        $this->sendForStop('stop_skipped', $stop);
    }

    public function stopRescheduled(RouteStop $stop, string $date): void
    {
        $this->sendForStop('stop_rescheduled', $stop, Carbon::parse($date)->format('d/m/Y'));
    }

    public function clientAdded(RouteStop $stop): void
    {
        $this->sendForStop('client_added', $stop);
    }

    public function meterDiscrepancy(Route $route, float $liters, ?string $note): void
    {
        if ($this->guarded()) {
            return;
        }

        $driver = $route->driver?->user?->name ?? auth()->user()->name;
        $detail = sprintf('%+d L%s', (int) round($liters), $note ? ' · '.Str::limit($note, 80) : '');

        Notification::send($this->recipients(), new ChoferRouteChanged(
            'meter_discrepancy', $driver, $route->route_date->toDateString(), null, $detail,
        ));
    }

    private function sendForStop(string $kind, RouteStop $stop, ?string $detail = null): void
    {
        if ($this->guarded()) {
            return;
        }

        $driver = $stop->route?->driver?->user?->name ?? auth()->user()->name;
        $date = $stop->route?->route_date?->toDateString()
            ?? $stop->scheduled_for?->toDateString()
            ?? today()->toDateString();

        Notification::send($this->recipients(), new ChoferRouteChanged(
            $kind, $driver, $date, $stop->customer_name, $detail,
        ));
    }

    /** @return Collection<int, User> */
    private function recipients(): Collection
    {
        return User::role('administrador')->where('is_active', true)->get();
    }

    /**
     * Solo se notifica una acción hecha por un chofer autenticado (su web operativa).
     * Seeders/comandos corren sin sesión o como admin -> no disparan nada; el tablero de
     * oficina nunca llama a este servicio.
     */
    private function guarded(): bool
    {
        return ! auth()->user()?->isDriver();
    }
}
