<?php

namespace App\Notifications;

use App\Support\Duration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * El sistema ha detectado una parada no programada (petición del usuario, 2026-09-14) —
 * StopDwellService::buildUnplannedStops(). Se envía a los administradores, una sola vez por
 * parada real (StopDwellService::run() no vuelve a notificar la misma tras recalcular).
 */
class UnplannedStopDetected extends Notification
{
    use Queueable;

    public function __construct(
        public string $driverName,
        public string $routeDate,
        public ?int $seconds,
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
            'type' => 'unplanned_stop_detected',
            'icon' => 'route',
            'title' => 'Parada no programada detectada',
            'body' => $this->body(),
            'url' => route('routes.board', ['date' => $this->routeDate]),
        ];
    }

    private function body(): string
    {
        $duration = $this->seconds !== null ? Duration::humanShort($this->seconds) : 'todavía en curso';

        return "{$this->driverName} ha hecho una parada no programada ({$duration}) el {$this->routeDate}.";
    }
}
