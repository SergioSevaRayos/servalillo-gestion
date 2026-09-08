<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Notifications\Concerns\LinksToTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Alguien respondió en un ticket de soporte (Bloque 12). Se envía al otro lado:
 * si respondió el creador -> mantenimiento; si respondió mantenimiento -> el creador.
 */
class SupportTicketReplied extends Notification
{
    use LinksToTicket, Queueable;

    public function __construct(public SupportTicket $ticket, public SupportTicketReply $reply) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_replied',
            'icon' => 'chat',
            'title' => 'Respuesta en una incidencia',
            'body' => trim(($this->reply->author?->name ?? 'Alguien').' respondió en: '.$this->ticket->subject),
            'url' => $this->urlFor($notifiable),
            'ticket_id' => $this->ticket->id,
        ];
    }
}
