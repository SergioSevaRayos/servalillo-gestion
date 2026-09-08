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

    /*
    |--------------------------------------------------------------------------
    | Optimización de rutas ("Ruta eficiente", Bloque 13)
    |--------------------------------------------------------------------------
    | Motor de reordenación de paradas: servicio OSRM /trip (TSP). El servidor
    | demo público no necesita API key; se puede autoalojar cambiando OSRM_URL.
    | Si OSRM falla / hace timeout / no responde, App\Services\RouteOptimizer
    | cae a una heurística local (vecino más cercano + 2-opt sobre haversine).
    */
    'routing' => [
        'enabled' => (bool) env('ROUTING_OSRM_ENABLED', true),
        'osrm_url' => rtrim((string) env('OSRM_URL', 'https://router.project-osrm.org'), '/'),
        'timeout' => (int) env('OSRM_TIMEOUT', 4),
        'connect_timeout' => (int) env('OSRM_CONNECT_TIMEOUT', 2),
    ],

];
