<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Un chofer se ha desviado del plan en su ruta (Bloque 12). Se envía a los administradores.
 * Solo para desviaciones: parada fallida/omitida, reprogramada, cliente añadido sobre la
 * marcha, o jornada cerrada con descuadre de litros. Las entregas normales no notifican.
 */
class ChoferRouteChanged extends Notification
{
    use Queueable;

    /** @param  'stop_failed'|'stop_skipped'|'stop_rescheduled'|'client_added'|'meter_discrepancy'  $kind */
    public function __construct(
        public string $kind,
        public string $driverName,
        public string $routeDate,
        public ?string $customerName = null,
        public ?string $detail = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'chofer_route_changed',
            'kind' => $this->kind,
            'icon' => 'route',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => route('routes.board', ['date' => $this->routeDate]),
        ];
    }

    private function title(): string
    {
        return match ($this->kind) {
            'stop_failed' => 'Parada fallida',
            'stop_skipped' => 'Parada omitida',
            'stop_rescheduled' => 'Parada reprogramada',
            'client_added' => 'Cliente añadido a una ruta',
            'meter_discrepancy' => 'Contador de litros descuadrado',
            default => 'Cambio en una ruta',
        };
    }

    private function body(): string
    {
        $who = $this->driverName;
        $cliente = $this->customerName ?? 'un cliente';

        return match ($this->kind) {
            'stop_failed' => "{$who} marcó fallida la parada de {$cliente}.",
            'stop_skipped' => "{$who} omitió la parada de {$cliente}.",
            'stop_rescheduled' => "{$who} reprogramó la parada de {$cliente} para el {$this->detail}.",
            'client_added' => "{$who} añadió a {$cliente} a su ruta del {$this->routeDate}.",
            'meter_discrepancy' => "{$who} cerró la jornada del {$this->routeDate} con un descuadre: {$this->detail}.",
            default => "{$who} modificó su ruta del {$this->routeDate}.",
        };
    }
}
