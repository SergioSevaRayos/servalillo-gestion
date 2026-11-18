# Mapa de flota, Avisos, DNI/CIF, Facturas y logotipo (2026-11-18)

Principio transversal pedido por el usuario: **el perfil de mantenimiento lo ve todo siempre**, aunque
algo esté apagado para el resto (ver `User::attendanceVisible()` para el fichaje). Las pantallas nuevas
van en el grupo de rutas **"Gestión"** (`role:administrador|mantenimiento`), nunca bajo `/mantenimiento`.

## Fichaje apagado + visible solo para mantenimiento
- `ATTENDANCE_ENABLED=false` en local y producción. `User::attendanceVisible()` =
  flag **o** `isMaintenance()`; sustituye a la comprobación directa del flag en las pantallas,
  exportaciones, menú y selectores de `/chofers` y `/usuarios`.
- Mantenimiento ve `/fichajes/gestion` y `/fichajes/totales`; **no ficha** (`canPunchAttendance()` sigue
  excluyéndolo → `/fichar` da 403). Administrador y chofer reciben 404.

## Logotipo
- `public/images/logo-aguas-del-servalillo.png`: recortado (`convert -trim`) y reducido a 480 px
  (~72 KB) desde `AGUAs.png` (1366×768, fondo transparente, mucho margen).
- Se usa en la cabecera del albarán PDF (`pdf/delivery-note.blade.php`, como **data URI** para no
  depender de rutas/chroot de dompdf), en el acceso (`layouts/guest.blade.php`) y, desde el menú lateral
  (2026-11-18, ver `docs/07`), en la cabecera de la navegación (escritorio y barra móvil): ya no hay barra
  superior con enlaces, así que el logo ancho no desborda nada.
- **Modo oscuro**: se usa el mismo logo, sin variante ni placa. Se probaron una placa blanca cuadrada y un
  borde blanco fino y el usuario descartó ambos por feos; el original (transparente) se lee bien sobre el fondo
  oscuro (rojo y azul con contraste suficiente, barra morada visible).

## DNI/CIF del cliente al entregar (web del chofer)
- `StopActionForm::$customer_tax_id`, visible con "Entregada". Se **precarga** desde
  `route_stops.customer_tax_id` o, si falta, desde la ficha (`matchingClient()`: CIF si hay, si no
  nombre exacto — mismo emparejamiento que `captureStopCoordinates()`; no hay `route_stops.client_id`).
- Se normaliza antes de validar (`normalizeTaxId()`: sin espacios/puntos/guiones, mayúsculas, vacío→null)
  y se valida con `^[A-Z0-9]{8,12}$` (deliberadamente laxo: admite DNI, NIE, CIF y NIF extranjeros).
- Al guardar: va a la parada (y por tanto al albarán, que ya lo pintaba) y, **si la ficha no tenía
  ninguno, también a la ficha** — nunca pisa uno existente. Opcional salvo que se pida factura.

## Facturas (trabajo de administración)
- **Antes no existía ningún concepto de factura.** La factura se hace **fuera de la app**; aquí solo
  se controla qué falta: `invoices` (`status` enum `InvoiceStatus` pendiente→emitida→enviada, `invoice_number`,
  `customer_snapshot` jsonb congelado, `service_date`, `issued_at`/`sent_at`, `notes`, `requested_by`,
  `handled_by`; `Auditable` + `SoftDeletes`; `route_stop_id` **único** → una factura por parada).
- `clients.always_invoice` ("Siempre factura", en `ClientForm` y la ficha) marca sola la casilla del
  chofer. `route_stops.invoice_requested` = foto fija de lo pedido en esa entrega.
- Chofer: casilla "El cliente quiere factura" → **hace obligatorio el DNI/CIF**
  (`Rule::requiredIf` en `StopActionForm::rules()`). `App\Services\InvoiceService::requestForStop()`
  (idempotente, `firstOrCreate`) + 7º tipo de aviso chofer→oficina `invoice_requested`
  (`RouteChangeNotifier::invoiceRequested()`, solo si la factura se crea ahora: reabrir y volver a
  cerrar no duplica ni vuelve a avisar).
- Administración: `/facturas` (`App\Livewire\Invoices\Index` + `InvoiceForm`, permiso **`invoices.manage`**,
  `InvoicePolicy`). Por defecto filtra "Pendiente". "Marcar emitida" **exige el nº de factura**;
  las marcas de tiempo siguen al estado (se estampan la primera vez y se limpian si se retrocede).
  Insignia con el nº de pendientes en el menú.

## Mapa de la flota — `/mapa`
- `App\Livewire\Fleet\Map`, permiso `devices.manage` (ya lo tienen administrador y mantenimiento).
  Última `GpsPosition` por dispositivo **activo con chofer**, más camión y estado de su ruta de hoy.
  Nueva migración: índice `gps_positions (device_id, recorded_at)` — antes la "última posición de un
  dispositivo parado" obligaba a recorrer la tabla por fecha.
- `wire:poll.30s="refreshMap"` + evento `fleet-map-updated`; el `<div>` del mapa va en `wire:ignore` y
  `Alpine.data('fleetMap')` **mueve** los marcadores sin recrear el mapa (respeta zoom/posición). Los
  objetos de Leaflet viven en el closure, no en `this` (evitar el Proxy reactivo de Alpine). Mismos
  tiles ArcGIS que el resto (orden z/y/x). Estados: en línea / sin señal / sin datos; "sin señal" usa el
  **mismo umbral** que el aviso de silencio GPS (`Map::staleMinutes()` → `AlertRule`).
- La última posición se toma por `recorded_at`, **no por orden de llegada** (un lote de recuperación
  llega tarde pero con horas antiguas).

## Avisos — `/avisos`
- Permiso **`alerts.manage`** (administrador + mantenimiento), `AlertRulePolicy`.
  `alert_rules` (una fila por `AlertType`, creada con sus defaults por `AlertRule::ensureDefaults()`,
  `Auditable`) y `alert_events` (estado de cada aviso concreto, **no auditado**, dato derivado).
- Tipos (`App\Enums\AlertType`): `gps_silence` (5 min), `low_battery` (15 %), `shift_not_started`
  (09:00), `shift_not_closed` (21:00), `speeding` (110 km/h, **dos lecturas seguidas** y recientes).
  Todos con interruptor; umbral u hora editables y validados (`AlertRulesForm`; la propiedad se llama
  `items`, no `rules`, porque chocaría con el método `rules()` de `Livewire\Form`).
- **`App\Services\AlertEvaluator` es el único punto de cálculo**; comando `avisos:evaluar` cada minuto
  (`routes/console.php`, `withoutOverlapping` + `Cache::lock`). Por tipo calcula los "sujetos" que cumplen
  y **reconcilia** contra `alert_events`: avisa **una vez al abrirse y otra al resolverse**, nunca en cada
  pasada (mismo patrón que `unplanned_stops.notified_at`). Un aviso apagado en caliente se cierra en
  silencio (sin "resuelto").
- Solo se esperan señales de **choferes con ruta operativa hoy** (Publicada/En curso): sin esto, un chofer
  de vacaciones dispararía el aviso todo el día. `gps_silence`, `low_battery` y `speeding` solo evalúan
  **dentro de la ventana de tracking** (`App\Support\TrackingWindow`, pausa 22:00–05:00 que cruza la
  medianoche); fuera de ella el detector devuelve `null` = "no aplica", y **no se cierra ningún aviso abierto**
  (si no, a las 22:00 llegaría un falso "resuelto" por el mero hecho de pausarse el tracking).
- `gps_silence` se mide con `devices.last_seen_at` (lo actualiza `GpsIngestService` en cada lote; una consulta
  barata por dispositivo, sin tocar `gps_positions`). Un dispositivo con ruta hoy que **nunca** ha enviado
  nada también cuenta.
- Notificación `App\Notifications\FleetAlert` (database, esquema `type/title/body/url/icon` de la campana),
  mismos destinatarios que `RouteChangeNotifier` (administrador + mantenimiento activos). Botón
  "Evaluar ahora" en la pantalla para probar un umbral sin esperar al minuto.

### Gestión del historial de avisos (2026-11-18)
- **Antes no había ningún tope**: la pantalla enseñaba solo los últimos 15 resueltos, pero las filas de
  `alert_events` no se borraban nunca (tabla creciendo sin límite).
- **Purga automática**: `avisos:purgar` (`PurgeAlertEvents`, diario 04:20) borra los **resueltos** con más de
  `config('servalillo.alerts_retention_days')` días (`ALERTS_RETENTION_DAYS`, def. **30**; opción `--dias=`).
  **Nunca borra un aviso activo**, por antiguo que sea. Mismo patrón que `accesos:purgar`.
- **Pantalla `/avisos`**: el bloque "Resueltos recientemente" pasa a **"Historial de avisos resueltos"**:
  paginado (15/página, antes un `limit(15)` seco), filtros por tipo y rango de fechas, casillas con
  "Seleccionar los de esta página" (no toca otras páginas), **"Eliminar seleccionados"** y **"Vaciar esta lista"**
  (respeta los filtros activos), ambos con `wire:confirm`. Muestra el total guardado y la retención en vigor.
- Defensa en profundidad: `deleteSelected()` y `deleteFiltered()` filtran siempre `resolved_at IS NOT NULL` en la
  propia consulta, así que aunque alguien cuele el id de un aviso activo en la selección no se borra.
- Un aviso **activo** no se puede descartar a mano a propósito: si la condición sigue dándose, el evaluador lo
  reabriría y volvería a notificar en la pasada siguiente. Se cierra solo cuando la condición desaparece.

## Despliegue
- **`deploy.sh` ahora reejecuta `RolePermissionSeeder` tras `migrate`** (idempotente): `invoices.manage` y
  `alerts.manage` llegan solos a producción (era el gotcha pendiente del Bloque 18).
- El planificador de producción ya ejecuta `schedule:run` cada minuto por cron, así que `avisos:evaluar`
  funciona sin cambios en el servidor.
