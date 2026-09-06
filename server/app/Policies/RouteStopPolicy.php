<?php

namespace App\Policies;

use App\Models\RouteStop;
use App\Models\User;

class RouteStopPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['routes.view', 'routes.view.own']);
    }

    public function view(User $user, RouteStop $stop): bool
    {
        if ($user->can('routes.view')) {
            return true;
        }

        return $user->can('routes.view.own') && $this->owns($user, $stop);
    }

    public function create(User $user): bool
    {
        return $user->can('routes.update');
    }

    public function update(User $user, RouteStop $stop): bool
    {
        return $user->can('routes.update');
    }

    public function delete(User $user, RouteStop $stop): bool
    {
        return $user->can('routes.update');
    }

    /** Marcar la parada como en curso / completada / fallida (operativa del chofer). */
    public function complete(User $user, RouteStop $stop): bool
    {
        return $user->can('deliveries.complete') && $this->owns($user, $stop);
    }

    public function recordSignature(User $user, RouteStop $stop): bool
    {
        return $user->can('deliveries.record_signature') && $this->owns($user, $stop);
    }

    protected function owns(User $user, RouteStop $stop): bool
    {
        return $user->driver !== null
            && $user->driver->id === $stop->route->driver_id;
    }
}
