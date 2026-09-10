<?php

namespace App\Policies;

use App\Models\TruckAssignment;
use App\Models\User;

class TruckAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('routes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('routes.create');
    }

    public function update(User $user, TruckAssignment $assignment): bool
    {
        return $user->can('routes.update');
    }

    public function delete(User $user, TruckAssignment $assignment): bool
    {
        return $user->can('routes.delete');
    }
}
