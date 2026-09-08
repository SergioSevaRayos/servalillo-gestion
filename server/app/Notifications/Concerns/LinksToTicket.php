<?php

namespace App\Notifications\Concerns;

/**
 * Resuelve el deep link de un ticket de soporte según quién recibe la notificación:
 * el administrador creador va a `/soporte`, mantenimiento a `/mantenimiento/soporte`.
 * Requiere una propiedad pública `$ticket` en la clase que lo usa. El notifiable
 * siempre es un App\Models\User en este proyecto.
 */
trait LinksToTicket
{
    protected function urlFor(object $notifiable): string
    {
        $adminOnly = $notifiable->hasRole('administrador') && ! $notifiable->hasRole('mantenimiento');

        return $adminOnly
            ? route('support.index', ['ticket' => $this->ticket->id])
            : route('maintenance.support', ['ticket' => $this->ticket->id]);
    }
}
