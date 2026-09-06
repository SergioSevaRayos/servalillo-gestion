<?php

namespace App\Policies;

use App\Models\DeliveryType;
use App\Models\User;

class DeliveryTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('delivery_types.view');
    }

    public function view(User $user, DeliveryType $type): bool
    {
        return $user->can('delivery_types.view');
    }

    public function create(User $user): bool
    {
        return $user->can('delivery_types.create');
    }

    public function update(User $user, DeliveryType $type): bool
    {
        return $user->can('delivery_types.update');
    }

    public function delete(User $user, DeliveryType $type): bool
    {
        // No permitir borrar un tipo en uso (se comprueba también en el Service).
        return $user->can('delivery_types.delete') && $type->stops()->doesntExist();
    }
}
