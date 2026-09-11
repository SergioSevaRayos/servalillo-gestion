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

    /*
    |--------------------------------------------------------------------------
    | Tiempo de permanencia en parada (geocerca por GPS)
    |--------------------------------------------------------------------------
    | `App\Services\StopDwellService` reprocesa las posiciones GPS del día y, para cada
    | parada con coordenadas, deriva los tramos en los que el camión estuvo dentro de
    | `radius_meters` de ella. Reglas de conteo (todas ajustables):
    | - `min_seconds`: por debajo no cuenta como parada (evita "pasaba por la calle").
    | - `merge_gap_seconds`: hueco MÁXIMO entre fixes que se puentea sin cortar la visita — el
    |   camión se asume parado durante ese hueco (típicamente pérdida momentánea de SEÑAL GPS:
    |   nave cubierta, patio techado — NO pérdida de cobertura de red, que no genera hueco real
    |   porque el móvil encola y reenvía las posiciones con su hora real en cuanto vuelve la
    |   conexión). Por encima de este umbral ya no se asume que siguiera parado y se corta en una
    |   visita nueva.
    | - `accuracy_reject_meters`: se ignoran fixes con `accuracy_m` peor que esto.
    | - `exclude_base_radius_meters`: se ignoran fixes junto a la nave (evita contar el
    |   camión aparcado en la base si hay un cliente pegado).
    | - `clamp_to_shift`: si el día tiene horario de jornada, se ignora lo de fuera.
    | - `moving_speed_min_mps`: para la MEDIA de velocidad entre dos paradas
    |   (`StopDwellService::transitLegs()`) — por debajo se considera que el camión estaba
    |   parado (esperando, un descanso, una parada real que no coincidió con ninguna geocerca) y
    |   esas muestras no cuentan, para que un tramo con minutos parado no dé una media
    |   artificialmente baja. El máximo no se filtra.
    | - `recompute_*`: control del recálculo perezoso al abrir el tablero / la web del chofer.
    */
    'dwell' => [
        'radius_meters' => (int) env('DWELL_RADIUS_M', 150),
        'min_seconds' => (int) env('DWELL_MIN_SECONDS', 120),
        'merge_gap_seconds' => (int) env('DWELL_MERGE_GAP_SECONDS', 600),
        'accuracy_reject_meters' => (int) env('DWELL_ACCURACY_REJECT_M', 150),
        'exclude_base_radius_meters' => (int) env('DWELL_EXCLUDE_BASE_RADIUS_M', 150),
        'moving_speed_min_mps' => (float) env('DWELL_MOVING_SPEED_MIN_MPS', 1.0),
        'clamp_to_shift' => (bool) env('DWELL_CLAMP_TO_SHIFT', true),
        'recompute_every_seconds' => (int) env('DWELL_RECOMPUTE_EVERY_SECONDS', 90),
        'recompute_max_age_days' => (int) env('DWELL_RECOMPUTE_MAX_AGE_DAYS', 2),
    ],

];
