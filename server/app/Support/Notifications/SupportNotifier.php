<?php

namespace App\Support\Notifications;

use App\Enums\SupportStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\SupportTicketOpened;
use App\Notifications\SupportTicketReplied;
use App\Notifications\SupportTicketStatusChanged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Notificaciones del canal de soporte (Bloque 12): apertura, respuesta y cambio de estado.
 * Se llama solo desde acciones reales de usuario en los componentes Livewire; el seeder
 * usa `->notify()` directamente.
 */
class SupportNotifier
{
    public function opened(SupportTicket $ticket): void
    {
        Notification::send($this->maintenance(), new SupportTicketOpened($ticket));
    }

    public function replied(SupportTicket $ticket, SupportTicketReply $reply): void
    {
        $recipients = $reply->user_id === $ticket->user_id
            ? $this->maintenance()                       // respondió el admin -> mantenimiento
            : collect([$ticket->creator])->filter();     // respondió mantenimiento -> creador

        Notification::send($recipients, new SupportTicketReplied($ticket, $reply));
    }

    public function statusChanged(SupportTicket $ticket, SupportStatus $old, SupportStatus $new): void
    {
        if (! $ticket->creator) {
            return;
        }

        $ticket->creator->notify(new SupportTicketStatusChanged($ticket, $old, $new));
    }

    /** @return Collection<int, User> */
    private function maintenance(): Collection
    {
        return User::role('mantenimiento')->where('is_active', true)->get();
    }
}
