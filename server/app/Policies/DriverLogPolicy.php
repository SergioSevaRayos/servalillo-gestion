<?php

namespace App\Policies;

use App\Models\DriverLog;
use App\Models\User;

class DriverLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('driver_logs.view');
    }

    public function view(User $user, DriverLog $log): bool
    {
        return $user->can('driver_logs.view');
    }

    public function create(User $user): bool
    {
        return $user->can('driver_logs.create');
    }

    public function update(User $user, DriverLog $log): bool
    {
        return $user->can('driver_logs.update');
    }

    public function delete(User $user, DriverLog $log): bool
    {
        return $user->can('driver_logs.delete');
    }

    public function restore(User $user, DriverLog $log): bool
    {
        return $user->can('driver_logs.delete');
    }
}
