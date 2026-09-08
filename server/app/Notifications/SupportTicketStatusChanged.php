<?php

namespace App\Notifications;

use App\Enums\SupportStatus;
use App\Models\SupportTicket;
use App\Notifications\Concerns\LinksToTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Mantenimiento cambió el estado de un ticket de soporte (Bloque 12). Se envía al creador.
 */
class SupportTicketStatusChanged extends Notification
{
    use LinksToTicket, Queueable;

    public function __construct(
        public SupportTicket $ticket,
        public SupportStatus $old,
        public SupportStatus $new,
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
            'type' => 'support_ticket_status_changed',
            'icon' => 'flag',
            'title' => 'Estado de incidencia actualizado',
            'body' => "«{$this->ticket->subject}»: {$this->old->label()} → {$this->new->label()}",
            'url' => $this->urlFor($notifiable),
            'ticket_id' => $this->ticket->id,
        ];
    }
}
