<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('support.create');
    }

    public function create(User $user): bool
    {
        return $user->can('support.create');
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $ticket->user_id === $user->id || $user->can('support.manage');
    }

    /**
     * El creador puede editar/borrar su incidencia mientras el otro lado (mantenimiento)
     * no haya respondido. Mantenimiento puede siempre.
     */
    public function update(User $user, SupportTicket $ticket): bool
    {
        if ($user->can('support.manage')) {
            return true;
        }

        return $ticket->user_id === $user->id
            && ! $ticket->replies()->whereNotNull('user_id')->where('user_id', '!=', $user->id)->exists();
    }

    public function delete(User $user, SupportTicket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.manage') || $ticket->user_id === $user->id;
    }

    public function manage(User $user): bool
    {
        return $user->can('support.manage');
    }
}
