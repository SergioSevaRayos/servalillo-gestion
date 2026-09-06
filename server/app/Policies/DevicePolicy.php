<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('devices.manage');
    }

    public function view(User $user, Device $device): bool
    {
        return $user->can('devices.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('devices.manage');
    }

    public function update(User $user, Device $device): bool
    {
        return $user->can('devices.manage');
    }

    public function delete(User $user, Device $device): bool
    {
        return $user->can('devices.manage');
    }
}
