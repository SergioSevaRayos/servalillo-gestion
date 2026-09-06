<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function delete(User $user, User $model): bool
    {
        // Nadie se borra a sí mismo.
        return $user->can('users.manage') && $user->id !== $model->id;
    }

    public function assignRoles(User $user, User $model): bool
    {
        return $user->can('roles.manage');
    }
}
