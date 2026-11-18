<?php

namespace App\Policies;

use App\Models\AlertRule;
use App\Models\User;

class AlertRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('alerts.manage');
    }

    public function update(User $user, AlertRule $rule): bool
    {
        return $user->can('alerts.manage');
    }
}
