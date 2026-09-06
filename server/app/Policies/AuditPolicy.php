<?php

namespace App\Policies;

use App\Models\User;
use OwenIt\Auditing\Models\Audit;

class AuditPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audits.view');
    }

    public function view(User $user, Audit $audit): bool
    {
        return $user->can('audits.view');
    }
}
