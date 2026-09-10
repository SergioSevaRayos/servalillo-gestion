<?php

namespace App\Policies;

use App\Models\OdometerReading;
use App\Models\RouteDay;
use App\Models\User;

class OdometerReadingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['routes.view', 'routes.view.own']);
    }

    public function view(User $user, OdometerReading $reading): bool
    {
        if ($user->isManager()) {
            return true;
        }

        return $user->driver !== null && $user->driver->id === $reading->driver_id;
    }

    public function create(User $user): bool
    {
        return $user->can('odometer.record');
    }

    public function record(User $user, RouteDay $route): bool
    {
        return $user->can('odometer.record')
            && $user->driver !== null
            && $user->driver->id === $route->driver_id;
    }
}
