<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Canal del mapa en tiempo real: solo perfiles de gestión ven las posiciones.
*/
Broadcast::channel('fleet-map', function (User $user) {
    return $user->isManager();
});
