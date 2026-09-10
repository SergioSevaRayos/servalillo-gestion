<?php

namespace App\Policies;

use App\Models\Route;
use App\Models\User;

class RoutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['routes.view', 'routes.view.own']);
    }

    public function view(User $user, Route $route): bool
    {
        if ($user->can('routes.view')) {
            return true;
        }

        return $user->can('routes.view.own') && $this->owns($user, $route);
    }

    public function create(User $user): bool
    {
        return $user->can('routes.create');
    }

    public function update(User $user, Route $route): bool
    {
        return $user->can('routes.update');
    }

    public function delete(User $user, Route $route): bool
    {
        return $user->can('routes.delete');
    }

    public function restore(User $user, Route $route): bool
    {
        return $user->can('routes.delete');
    }

    protected function owns(User $user, Route $route): bool
    {
        return $user->driver !== null && $user->driver->id === $route->driver_id;
    }
}
