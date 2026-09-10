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
    | Retención del registro de accesos (login_logs)
    |--------------------------------------------------------------------------
    */
    'login_log_retention_days' => (int) env('LOGIN_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | "Conectado ahora" en Mantenimiento → Accesos
    |--------------------------------------------------------------------------
    | Un usuario se considera activo si su última petición (users.last_seen_at,
    | actualizado por EnsureUserIsActive con este mismo margen de tolerancia)
    | quedó dentro de esta ventana.
    */
    'online_window_minutes' => (int) env('ONLINE_WINDOW_MINUTES', 3),

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
    | Dispositivo de tracking (APK, Bloque 10)
    |--------------------------------------------------------------------------
    | La APK "tracker" (sin interfaz) se enrola contra POST /api/device/register
    | enviando este secreto compartido. Se emite un token Sanctum con habilidad
    | `gps:ingest`. El servicio técnico asigna luego el dispositivo a un chofer
    | desde el panel de Mantenimiento.
    */
    'device' => [
        'enrolment_secret' => (string) env('DEVICE_ENROLMENT_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optimización de rutas ("Ruta eficiente", Bloque 13)
    |--------------------------------------------------------------------------
    | Motor de reordenación de paradas: matriz de distancias reales por
    | carretera de OSRM /table + vecino más cercano y 2-opt. El servidor demo
    | público no necesita API key; se puede autoalojar cambiando OSRM_URL. Si
    | OSRM falla / hace timeout / no responde, App\Services\RouteOptimizer cae
    | a una heurística local (mismo algoritmo sobre distancia haversine).
    */
    'routing' => [
        'enabled' => (bool) env('ROUTING_OSRM_ENABLED', true),
        'osrm_url' => rtrim((string) env('OSRM_URL', 'https://router.project-osrm.org'), '/'),
        'timeout' => (int) env('OSRM_TIMEOUT', 4),
        'connect_timeout' => (int) env('OSRM_CONNECT_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Base de la flota
    |--------------------------------------------------------------------------
    | Ubicación desde la que salen los camiones. "Ruta eficiente" la ofrece
    | como punto de partida ("Desde la base") al reordenar una ruta.
    */
    'base' => [
        'latitude' => (float) env('BASE_LATITUDE', 36.876880),
        'longitude' => (float) env('BASE_LONGITUDE', -2.443087),
    ],

];
