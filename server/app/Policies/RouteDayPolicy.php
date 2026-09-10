<?php

namespace App\Policies;

use App\Models\RouteDay;
use App\Models\User;

class RouteDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['routes.view', 'routes.view.own']);
    }

    public function view(User $user, RouteDay $route): bool
    {
        if ($user->can('routes.view')) {
            return true;
        }

        return $user->can('routes.view.own') && $this->owns($user, $route);
    }

    public function reorderStops(User $user, RouteDay $route): bool
    {
        return $user->can('routes.reorder_stops');
    }

    /** El chofer puede pedir "organizar mi ruta" solo sobre una ruta suya. */
    public function optimizeOwn(User $user, RouteDay $route): bool
    {
        return $user->can('routes.optimize.own') && $this->owns($user, $route);
    }

    /** El chofer solo puede operar su propia ruta. */
    public function operate(User $user, RouteDay $route): bool
    {
        return $user->can('routes.view.own') && $this->owns($user, $route);
    }

    protected function owns(User $user, RouteDay $route): bool
    {
        return $user->driver !== null && $user->driver->id === $route->driver_id;
    }
}
