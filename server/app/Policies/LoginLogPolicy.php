<?php

namespace App\Policies;

use App\Models\LoginLog;
use App\Models\User;

class LoginLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('system_logs.view');
    }

    public function view(User $user, LoginLog $log): bool
    {
        return $user->can('system_logs.view');
    }
}
