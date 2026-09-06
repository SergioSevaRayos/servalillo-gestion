<?php

use App\Support\DeliveryChannels\EmailChannel;
use App\Support\DeliveryChannels\PhysicalChannel;

return [

    /*
    |--------------------------------------------------------------------------
    | Canales de entrega de albaranes
    |--------------------------------------------------------------------------
    | Cada canal es una clase que implementa App\Contracts\DeliveryChannel.
    | Añadir un canal nuevo (ej. WhatsApp) = crear la clase y registrarla aquí.
    | La clave es lo que se guarda en delivery_notes.delivery_channel.
    */
    'channels' => [
        'email' => EmailChannel::class,
        'physical' => PhysicalChannel::class,
    ],

    'default' => 'email',

];
