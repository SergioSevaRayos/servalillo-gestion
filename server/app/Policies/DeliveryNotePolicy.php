<?php

namespace App\Policies;

use App\Models\DeliveryNote;
use App\Models\User;

class DeliveryNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('delivery_notes.view');
    }

    public function view(User $user, DeliveryNote $note): bool
    {
        if ($user->can('delivery_notes.view') && $user->isManager()) {
            return true;
        }

        // El chofer ve los albaranes de sus propias paradas.
        return $user->can('delivery_notes.view')
            && $user->driver !== null
            && $user->driver->id === $note->routeStop?->route?->driver_id;
    }

    public function regenerate(User $user, DeliveryNote $note): bool
    {
        return $user->can('delivery_notes.regenerate');
    }

    public function markDelivered(User $user, DeliveryNote $note): bool
    {
        return $user->can('delivery_notes.mark_delivered');
    }
}
