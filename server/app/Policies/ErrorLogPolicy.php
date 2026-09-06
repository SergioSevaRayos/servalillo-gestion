<?php

namespace App\Policies;

use App\Models\ErrorLog;
use App\Models\User;

class ErrorLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('system_logs.view');
    }

    public function view(User $user, ErrorLog $log): bool
    {
        return $user->can('system_logs.view');
    }
}
