<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.manage');
    }
}
