<?php

namespace App\Notifications;

use App\Enums\AlertType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Aviso de flota (/avisos): se envía a administración al abrirse y al resolverse. Solo canal database. */
class FleetAlert extends Notification
{
    use Queueable;

    /** @param  'opened'|'resolved'  $kind */
    public function __construct(
        public string $kind,
        public AlertType $type,
        public string $subject,
        public string $message,
        public ?string $url = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $resolved = $this->kind === 'resolved';

        return [
            'type' => 'fleet_alert',
            'kind' => $this->kind,
            'alert' => $this->type->value,
            'icon' => $this->type->icon(),
            'title' => ($resolved ? 'Resuelto: ' : '').$this->type->label(),
            'body' => $resolved ? "{$this->subject}: ya no se da este aviso." : $this->message,
            'url' => $this->url ?? route('fleet.map'),
        ];
    }
}
