# Terminal vinculado a ruta (Bloque 16) y captura de coordenadas

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Terminal vinculado a una ruta (Bloque 16, 2026-09-11) — chofer sustituto
- **Qué resuelve**: si el chofer titular de una ruta se pone malo, un sustituto solo tiene que
  iniciar sesión en la web desde el teléfono del camión (ya "vinculado" a esa ruta) para pasar
  **directamente** a gestionarla ese día, con su propio nombre — sin que oficina reasigne nada a
  mano en el caso normal. Petición explícita del usuario: "pasa directamente" → **sin pantalla de
  confirmación**.
- **`route_terminals`** (`route_id` → `routes` permanente, `cascadeOnDelete`; `token` único
  `Str::random(48)` — el valor opaco que vive en la cookie, nunca el id de ruta en crudo, así es
  revocable; `label`, `paired_at`/`last_used_at`, `created_by`, `revoked_at` — baja blanda).
  `App\Models\RouteTerminal`: modelo plano, **no** `Auditable` (registro de uso, mismo criterio que
  `GpsPosition`/`LoginLog`), sin `SoftDeletes` (`revoked_at` ya cumple esa función).
  `Route::terminals(): HasMany`.
- **Vincular** (`/rutas/listado`, botón "Terminales" por fila → `App\Livewire\Routes\Index::
  openTerminals/createTerminal/revokeTerminal`, gateado por `$this->authorize('update', $route)` —
  reutiliza `RoutePolicy::update` = permiso `routes.update`, **sin permiso ni policy nuevos**, un
  terminal siempre cuelga de una ruta): genera un enlace `{APP_URL}/terminal/vincular/{token}` con
  copiar-al-portapapeles (reutiliza el idioma `navigator.clipboard.writeText` + fallback de
  seleccionar texto que ya usaba `Alpine.data('prospectSummary')`, `resources/js/app.js:722` — sin
  librería de QR, no hay ninguna en el proyecto).
- **`GET /terminal/vincular/{token}`** (`App\Http\Controllers\RouteTerminalController::pair()`)
  **sin middleware `auth`/`role`** — igual que `theme.update`, va justo al lado en
  `routes/web.php`: quien lo visita puede no tener sesión iniciada todavía en ese navegador. 404
  llano si el token no existe o está revocado (el token es largo e improbable de adivinar, mismo
  nivel de confianza que la URL firmada de verificación de email que ya usa Laravel). Sella
  `paired_at` (solo la primera vez) y `last_used_at`, adjunta `Cookie::forever('route_terminal',
  $token)` — cookie **cifrada normal**, NO se añade a `encryptCookies(except: ['theme'])` (esa
  excepción es solo para el tema, que lo lee JS directo).
- **Al entrar como chofer**: la rama `isDriver()` de la ruta `home` (`routes/web.php`) delega en
  `App\Services\RouteTerminalPairingService::resolveDriverHome(User): string` (devuelve el
  **nombre** de ruta, `route()` lo resuelve a URL en el propio closure —
  `route(app(...)->resolveDriverHome($user))`, **nunca** pasar el nombre a secas a `redirect()`,
  que lo trataría como una URL literal — bug real que se coló y rompía el login de TODOS los
  chofers, detectado por los tests antes de desplegar). Sin `Driver`, sin cookie, o token
  revocado/inválido → no toca nada (flujo normal). Si el día de hoy de esa ruta
  (`RecurringRouteService::ensureForDate()`) ya es de este chofer → no-op. Si no:
  - **Guarda de colisión**: si el chofer ya tiene su propia ruta asignada hoy (otro `RouteDay` con
    su `driver_id` esa fecha), **no se sustituye** — un chofer con dos `RouteDay` el mismo día
    rompería varias asunciones del Bloque 15/10 que asumen 1 ruta = 1 chofer = 1 día (el *fallback*
    de GPS de `StopDwellService::positionsFor()` ya traía un comentario textual sobre esto: *"el
    índice único route_id+route_date ya lo garantiza, pero por si acaso"* — cierto solo mientras
    `driver_id` no pudiera cambiar tras crear el día, que es justo lo que esta función rompe).
  - Si no hay colisión: reasigna `RouteDay.driver_id` al sustituto (auditado automáticamente —
    `RouteDay` ya es `Auditable` y `driver_id` no está en `$auditExclude`, así que queda quién era
    el chofer original sin columna nueva) y, si el chofer original tenía un `Device` (GPS tracker,
    Bloque 10) asignado y el sustituto **no tiene ya el suyo propio** (`devices.driver_id` es
    único), reasigna también ese `Device` (mismo patrón que `Maintenance\Devices::assign()`,
    también auditado solo) — así el recorrido/velocidad/permanencia del Bloque 15 de ese día salen
    bien. Si el sustituto ya tiene su propio dispositivo, se deja tal cual, sin robárselo.
- **Se autocorrige solo al día siguiente**: `RecurringRouteService::ensureForDate()`/
  `generateForDate()` usan `firstOrCreate` — nunca tocan `driver_id` de un `RouteDay` que ya
  existe, solo lo copian de la `Route` permanente al CREAR la fila. Como la sustitución solo toca
  el día de HOY, la generación de mañana parte limpia del `driver_id` de la ruta permanente sin
  que haga falta ningún código de "revertir".
- **Válvula manual para oficina** (`/rutas/{route}/historial`, botón "Reasignar chofer" por fila,
  mismo patrón exacto que el selector de estado — `openReassignModal`/`reassignDriver` en
  `App\Livewire\Routes\History`): deshace una sustitución automática equivocada, fuerza una que la
  guarda de colisión bloqueó, o devuelve la ruta a mano a media jornada. Checkbox "Reasignar
  también el dispositivo GPS" (marcado por defecto), mismo mecanismo de reasignación.
- Tests: `RouteTerminalPairingTest` (vincular/404 en token inválido o revocado/sustitución real +
  reasignación del `Device`/no-op para el titular/guarda de colisión/no se filtra al día
  siguiente/sin perfil de Driver o no-chofer sin efecto/no roba el dispositivo si el sustituto ya
  tiene el suyo), `RouteTerminalsManagementTest` (CRUD de terminales + autorización),
  ampliación de `RouteHistoryTest` (reasignación manual + autorización).

### Capturar coordenadas de un cliente desde el móvil del chofer (2026-09-11)
- En el modal de completar parada (`livewire/chofer/today.blade.php`), donde normalmente sale
  "Cómo llegar" (si la parada ya tiene `latitude`/`longitude`), si **no** las tiene sale un botón
  **"Capturar ubicación"**: usa el GPS del propio móvil (`navigator.geolocation.getCurrentPosition`,
  `enableHighAccuracy: true`, mismo patrón que `tracker-test.blade.php`) — pensado para que el
  chofer, estando físicamente en el sitio, la capture con un toque sin depender de que oficina la
  rellene a mano. Errores de geolocalización (sin permiso, sin soporte, timeout) se avisan con un
  toast **disparado desde el propio JS** (`window.dispatchEvent(new CustomEvent('toast', {...}))`,
  ver el comentario de uso en `components/ui/toast-container.blade.php`), sin ida y vuelta al
  servidor.
- `App\Livewire\Chofer\Today::captureStopCoordinates(RouteStop $stop, float $lat, float $lng)` —
  autorización = `RouteStopPolicy::complete` (la misma que abrir la parada, sin permiso nuevo).
  Guarda en **dos sitios**: (1) la propia `route_stops.latitude/longitude` (efecto inmediato: mapa,
  ruta eficiente, tiempo en parada de hoy) y (2) si encuentra el cliente por CIF/nombre (mismo
  emparejamiento que `Client::pastStops()`: `customer_tax_id` si lo hay, si no `customer_name`
  exacto — **no** hay `route_stops.client_id`, ver "Gestión de clientes") y ese cliente **todavía
  no tiene coordenadas**, se las rellena también — así las próximas paradas de ese cliente
  (recurrentes o planificadas) ya nacen con ubicación. Si el cliente ya tenía coordenadas, no se
  tocan (nunca pisa un dato existente, solo rellena huecos).
  **(2026-11-25)** Además rellena las paradas `Pending` **ya planificadas** de ese cliente sin
  ubicación (`Client::fillPendingStopCoordinates()`, el mismo relleno que usa `ClientForm`, movido
  al modelo): antes solo nacían con ubicación las paradas generadas después, y el chofer volvía a
  ver "Capturar ubicación" la semana siguiente. Test: `tests/Feature/ChoferCaptureCoordinatesTest.php`.
- **Dirección inversa (2026-09-24): `ClientForm::save()` rellena las paradas `Pending` ya
  creadas cuando el CLIENTE recibe coordenadas después.** Bug real reportado: un cliente
  planificado con meses de antelación (cientos de `RouteStop` ya creadas, foto fija de los
  datos del cliente en ese momento) sin coordenadas — al añadírselas después al cliente (a
  mano o con el buscador de direcciones), las paradas que ya existían se quedaban huérfanas de
  ubicación para siempre y no aparecían en "Ver recorrido" ("N parada(s) sin ubicación no se
  muestra(n)"), sin ningún mecanismo que las sincronizara hacia atrás.
  `ClientForm::backfillPendingStopCoordinates()`, llamado tras guardar (alta y edición): mismo
  emparejamiento CIF/nombre que `Client::pastStops()`/`ClientDeliverySuspender`, mismo gate
  `whereNull('latitude')` que ya usa `captureStopCoordinates()` (nunca pisa una parada que ya
  tenga su propia coordenada) — pero **solo sobre `Pending`** (las cerradas son historial, no
  se tocan). Query builder a propósito, no Eloquent: no dispara `RouteStopObserver` (no debe
  notificar al chofer "parada modificada por oficina" por cada parada tocada) ni auditoría —
  mismo criterio que el generador nocturno de recurrentes ("es sistémica, no 'gestión'").

