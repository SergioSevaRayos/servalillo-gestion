<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Notifications\Concerns\LinksToTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Administración ha abierto una incidencia de soporte (Bloque 12). Se envía a mantenimiento.
 */
class SupportTicketOpened extends Notification
{
    use LinksToTicket, Queueable;

    public function __construct(public SupportTicket $ticket) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_opened',
            'icon' => 'wrench',
            'title' => 'Nueva incidencia de soporte',
            'body' => trim(($this->ticket->creator?->name ?? 'Administración').' abrió: '.$this->ticket->subject),
            'url' => $this->urlFor($notifiable),
            'ticket_id' => $this->ticket->id,
        ];
    }
}
