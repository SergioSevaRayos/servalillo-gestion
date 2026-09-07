<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clients.view');
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can('clients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('clients.update');
    }

    /** Aprobar un pre-cliente ("Pendiente valoración") y convertirlo en cliente real. */
    public function approve(User $user, Client $client): bool
    {
        return $user->can('clients.update');
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->can('clients.delete');
    }

    public function restore(User $user, Client $client): bool
    {
        return $user->can('clients.delete');
    }
}
