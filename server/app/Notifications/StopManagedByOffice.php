<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Oficina ha tocado una parada de la ruta de un chofer desde el panel de administración
 * (Bloque 14): añadida, modificada, quitada o reasignada. Se envía al chofer dueño de esa
 * ruta. NO se dispara por la generación automática de paradas recurrentes ni por las acciones
 * del propio chofer (ver `App\Observers\RouteStopObserver`).
 */
class StopManagedByOffice extends Notification
{
    use Queueable;

    /** @param  'added'|'modified'|'removed'  $kind */
    public function __construct(
        public string $kind,
        public string $customerName,
        public string $routeDate,
        public string $actorName,
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
            'type' => 'stop_managed_by_office',
            'kind' => $this->kind,
            'icon' => 'route',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => route('chofer.today', ['date' => $this->routeDate]),
        ];
    }

    private function title(): string
    {
        return match ($this->kind) {
            'added' => 'Parada añadida a tu ruta',
            'modified' => 'Parada modificada en tu ruta',
            'removed' => 'Parada quitada de tu ruta',
            default => 'Cambio en tu ruta',
        };
    }

    private function body(): string
    {
        $fecha = Carbon::parse($this->routeDate)->format('d/m/Y');
        $cliente = $this->customerName ?: 'un cliente';

        return match ($this->kind) {
            'added' => "Oficina ({$this->actorName}) añadió la parada de {$cliente} a tu ruta del {$fecha}.",
            'modified' => "Oficina ({$this->actorName}) cambió la parada de {$cliente} en tu ruta del {$fecha}.",
            'removed' => "Oficina ({$this->actorName}) quitó la parada de {$cliente} de tu ruta del {$fecha}.",
            default => "Oficina ({$this->actorName}) tocó tu ruta del {$fecha}.",
        };
    }
}
