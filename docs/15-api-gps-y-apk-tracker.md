# API de tracking GPS y APK tracker (Bloques 10-11)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### API de tracking GPS (Bloque 10)
- **La única API de la app** (`routes/api.php`). El "Bloque 10 — API Flutter" del contrato original de
  `docs/01` §4 (rutas/paradas/albaranes del chofer) **NO se construyó**: la web del chofer (Livewire)
  cubre esa operativa. Flutter es solo una **APK "tracker" sin interfaz** que el servicio técnico
  instala en el móvil de cada camión.
- **`POST /api/device/register`** (sin auth, `throttle:device-register` = 10/min por IP): la APK envía
  `{ install_identifier, secret, platform?, app_version? }`. `secret` se compara con
  `config('servalillo.device.enrolment_secret')` vía `hash_equals` (secreto vacío → 403). Upsert del
  `Device` por `install_identifier` (**no se toca `driver_id`** — enrolamiento "en blanco"); revoca
  tokens anteriores; emite un token Sanctum en el **`Device`** (`HasApiTokens`) con habilidad
  `gps:ingest`. Devuelve `{ token, device_id, tracking: {ping_interval_seconds, ping_distance_meters,
  pause_start, pause_end} }`.
- **`GET /api/device`** (`auth:sanctum` + `abilities:gps:ingest`, añadido en el Bloque 11):
  `DeviceApiController@show`. Estado para la pantalla de la APK → array plano (NO Resource)
  `{ device_id, label, is_active, driver: {name}|null, tracking, server_time }`. `driver.name` sale de
  `device->driver->user->name`. **NO aborta** si `is_active` es false: devuelve 200 con
  `is_active:false` para que la APK pare con elegancia (sí aborta 403 si el token no es de un `Device`).
- **`POST /api/gps/batch`** (`auth:sanctum` + `abilities:gps:ingest` — aliases añadidos en
  `bootstrap/app.php`): `{ positions: [{lat, lng, recorded_at, accuracy_m?, speed_mps?, heading_deg?,
  battery_level?}] }`, máx **500** por lote. `StoreGpsBatchRequest` + `App\Services\GpsIngestService`:
  descarta posiciones con `recorded_at > now()+1h`; por cada fecha resuelve
  `$route = RouteDay::forDate($d)->where('driver_id', $device->driver_id)->first()` → `driver_id` (del
  device), `truck_id`/`route_id` (de la ruta, **null si el device no tiene chofer o el chofer no tiene
  ruta ese día**); `GpsPosition::insert()` en bloque (sin eventos, no auditada); bumpea
  `device.last_seen_at`. `abort_unless($device instanceof Device, 403)` en el controlador (un token de
  usuario nunca tiene `gps:ingest` en prod, pero se blinda).
  - **Bug real de producción corregido (2026-09-11): `recorded_at` sin convertir de zona.** La APK
    manda `recorded_at` en UTC (`DateTime.toUtc().toIso8601String()`); `gps_positions.recorded_at`
    es un `timestamp` **sin zona**, igual que `route_days.started_at`/`completed_at` (siempre
    escritos con `now()`, ya en hora local). `GpsIngestService` hacía `Carbon::parse($p['recorded_at'])`
    sin convertir: en producción (`APP_TIMEZONE=Europe/Madrid`) esto guardaba la hora UTC tal cual,
    y al releerla se reinterpretaba como si ya fuera hora de Madrid → desplazada 2 h en verano
    (CEST) / 1 h en invierno (CET) frente a cualquier comparación con `now()`/`started_at`. Pasó
    desapercibido en local/tests porque ahí `APP_TIMEZONE` es `UTC` por defecto (mismo valor que
    manda la APK → el bug era invisible). Detectado al depurar por qué una parada real no generaba
    visita en el tiempo de permanencia (Bloque 15): el paso real por la geocerca a las 08:58 hora
    de Madrid se guardaba como 06:58 y caía fuera de la ventana de jornada. **Fix**:
    `Carbon::parse($p['recorded_at'])->setTimezone(config('app.timezone'))` antes de calcular
    `$dateKey` y de guardar — test en `GpsBatchTest` que fija `config('app.timezone')` a
    `Europe/Madrid` y comprueba el valor **crudo** en BD (no el casteado por Eloquent, que depende
    del timezone por defecto de PHP fijado al arrancar, no del que se cambie en caliente en el
    test). Esto también arregla (silenciosamente, ya venían mal) la frescura de "Ubicación actual
    del camión" en Ruta eficiente (`RouteOptimizer::latestVehiclePosition`, filtraba por
    `recorded_at >= now()-N min` y con el desfase casi nunca encontraba nada) y el indicador "en
    parada ahora" del Bloque 15. Los `gps_positions.recorded_at` **ya guardados** en producción con
    el bug se corrigieron a mano con `UPDATE gps_positions SET recorded_at = (recorded_at AT TIME
    ZONE 'UTC') AT TIME ZONE 'Europe/Madrid';` (reinterpreta el valor crudo como UTC y lo convierte
    a hora de Madrid — Postgres ya tiene en cuenta el cambio de hora, más seguro que sumar un
    intervalo fijo) + recálculo de permanencia del día afectado.
- **Esquema**: `devices.truck_id` (unique, NOT NULL) → **`devices.driver_id`** (nullable, unique,
  `nullOnDelete`). `gps_positions.truck_id` → **nullable**. `Truck::device()` eliminado.
- **Panel** (desde 2026-11-25 en **`/dispositivos`**, `App\Livewire\Devices\Index`, grupo Gestión — ver
  `docs/24`; antes `/mantenimiento/dispositivos`, `Maintenance\Devices`, tab, permiso
  `devices.manage` que ya tienen `mantenimiento` y `administrador`): lista de APKs enroladas (última
  señal, versión, nº posiciones, estado), `<select>` para **asignar/reasignar chofer** (`assign()` —
  avisa si el chofer ya tiene otro dispositivo, el `driver_id` es unique), **revocar** acceso (quitado del menú el 2026-11-25, ver `docs/24`)
  (`$device->tokens()->delete()`), **activar/desactivar**, **eliminar** (borra sus `gps_positions` por
  cascade), y **"Localizar"** (`locate()`, solo si tiene posiciones): emite `open-device-map` con la
  última posición + un rastro de las ≤60 recientes; `<x-device-map-modal>` + `Alpine.data('deviceMap')`
  (mapa Leaflet, círculo de precisión, línea punteada del rastro) — misma infra que `<x-route-map-modal>`
  del Bloque 13.
- **Protección del terminal** (Device Owner, bloqueo de capturas, clave, `POST /api/device/state`,
  plugin `mobile/packages/device_policy`): todo en `docs/24-proteccion-terminal-chofer.md`.
- **`gps:purgar {--dias=}`** (`App\Console\Commands\PurgeGpsPositions`, schedule diario 04:00): borra
  `gps_positions` anteriores a `config('servalillo.gps_retention_days')` en lotes de 5000.
- `bootstrap/app.php` ya devuelve JSON en `api/*` y loguea toda excepción a `error_logs` → los errores
  de la API salen solos en el panel de Mantenimiento. El grupo `api` de Laravel aquí es solo
  `SubstituteBindings` (sin `throttle:api`).
- Tests: `tests/Feature/Api/{DeviceRegisterTest,GpsBatchTest,DeviceShowTest}.php`,
  `PurgeGpsPositionsTest.php`, `MaintenanceDevicesTest.php`. Token de device en tests:
  `Sanctum::actingAs($device, ['gps:ingest'])` o
  `$device->createToken('t', ['gps:ingest'])->plainTextToken` + `->withToken(...)`. `phpunit.xml` fija
  `DEVICE_ENROLMENT_SECRET=test-enrolment-secret`.

### APK tracker (Bloque 11) — `mobile/`

- **APK Android headless** (Flutter). La instala el servicio técnico en el móvil de cada camión; se
  abre una vez para permisos y luego comparte ubicación en 2º plano para siempre. **Sin login ni UI
  del chofer.** Consume `POST /api/device/register`, `GET /api/device`, `POST /api/gps/batch`.
- **Toolchain para compilar** (no hace falta Android Studio): JDK 17 (`~/tools/jdk-17.0.20.1+1`,
  Java 21/25 no valen para AGP) + Android SDK (`~/Android`, cmdline-tools, `platforms;android-36`).
  `export JAVA_HOME=~/tools/jdk-17.0.20.1+1` antes de cualquier `flutter`/`gradle`.
- **Config = compile-time** (`--dart-define-from-file=dart_define.json`, gitignored):
  `SERVER_URL` (default = dev tunnel), `ENROLMENT_SECRET` (= `DEVICE_ENROLMENT_SECRET` del servidor,
  sin default), `APP_VERSION`. Plantilla `mobile/dart_define.example.json`. Cambiar la URL/secreto
  exige recompilar.
- Build: `cd mobile && flutter build apk --release --dart-define-from-file=dart_define.json` →
  `build/app/outputs/flutter-apk/app-release.apk` (firmado con la clave debug — sideload).
- **El túnel de VS Code NO sirve para el tracker** (latencia >100 s/petición > timeout de 25 s del
  cliente → "no se pudo conectar" en bucle, aunque el device sí se crea en el servidor). Para probar
  sin VPS: PC + móvil en la misma Wi-Fi, `SERVER_URL=http://<IP-LAN>:8000`. El APK lleva
  `usesCleartextTraffic=true` + `res/xml/network_security_config.xml` para permitir `http://`;
  **quitar esas dos líneas del manifest para la build de producción** (servidor por HTTPS).
- **Gotchas de plataforma ya resueltos** (todos detectados con `adb logcat`, móvil por Wi-Fi):
  (1) `MainActivity` tiene que estar en el paquete del `namespace` (`es.servalillo.tracker`), no en
  `es.servalillo.servalillo_tracker` que genera `flutter create` — si no, `ClassNotFoundException` al
  abrir. (2) **NUNCA** `db.execute('PRAGMA journal_mode=WAL')` en `openAppDatabase()`: en Android
  devuelve una fila y `execute()` la rechaza → `DatabaseException` → el `onStart` del servicio muere
  antes de montar el controller (0 envíos). sqflite ya gestiona WAL solo. (3) el servicio arranca con
  `PermissionsState.canTrack` (ubicación "mientras se usa" + notificaciones), no con `allGranted`;
  "ubicación siempre" + batería se recomiendan pero no bloquean.
- **Arquitectura Dart**: servicio en 1er plano (`flutter_foreground_task`, `TaskHandler` +
  `@pragma('vm:entry-point') startCallback()`) → cada tick: `pause_window` → `location_sampler` →
  `position_queue` (SQLite, cola offline ≤50k) → `sync_service` (lotes ≤500, borra tras 2xx) →
  `tracker_controller` mapea el resultado a notificación / parar servicio / saltar N ticks.
  `enrolment_service` (UUID persistente + token en `flutter_secure_storage`). `status_screen` = única
  pantalla (permisos + estado). Todo lo testeable tiene test en `mobile/test/` (53, con
  `sqflite_common_ffi` y `MockClient`).
- `flutter analyze` limpio + `dart format` + `flutter test` antes de dar por bueno. Detalle de
  instalación, permisos y mataprocesos OEM en `mobile/README.md`.

