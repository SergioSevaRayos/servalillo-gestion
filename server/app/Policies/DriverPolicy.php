<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('drivers.view');
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->can('drivers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('drivers.create');
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->can('drivers.update');
    }

    public function delete(User $user, Driver $driver): bool
    {
        return $user->can('drivers.delete');
    }

    public function restore(User $user, Driver $driver): bool
    {
        return $user->can('drivers.delete');
    }
}
