<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ventana de tracking GPS
    |--------------------------------------------------------------------------
    | El tracking se pausa entre estas horas (hora del servidor / app.timezone).
    | La app Flutter usa estos valores vía el endpoint de configuración.
    */
    'tracking' => [
        'pause_start' => env('TRACKING_PAUSE_START', '22:00'),
        'pause_end' => env('TRACKING_PAUSE_END', '05:00'),
        // Intervalo sugerido de envío de posiciones (segundos) y por distancia (metros)
        'ping_interval_seconds' => env('GPS_PING_INTERVAL', 45),
        'ping_distance_meters' => env('GPS_PING_DISTANCE', 75),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retención de posiciones GPS
    |--------------------------------------------------------------------------
    */
    'gps_retention_days' => (int) env('GPS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Contador de litros
    |--------------------------------------------------------------------------
    | Al terminar la jornada, si (lectura fin − lectura inicio) del contador no
    | cuadra con lo repartido a los clientes por más de esta tolerancia, se avisa
    | al chofer y se le pide un motivo del ajuste.
    */
    'liter_meter_tolerance' => (int) env('LITER_METER_TOLERANCE', 0),

];
