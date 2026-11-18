# Clientes, pre-clientes y tipo de servicio (Bloque 9)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Gestión de clientes (Bloque 9)
- **Módulo independiente** (decisión del usuario): NO hay `route_stops.client_id`, el editor de
  paradas del Kanban no se tocó. El histórico por cliente se empareja en `Client::pastStops()` por
  `route_stops.customer_tax_id` (si el cliente tiene CIF) o por `route_stops.customer_name` exacto.
  Si algún día se quiere un enlace fuerte, es una migración + un `belongsTo` nuevos, no un refactor.
- `/clientes` (`App\Livewire\Clients\Index`) y `/clientes/{client}` (`App\Livewire\Clients\Show`,
  **página propia**, no modal) — grupo `role:administrador|mantenimiento`, `permission:clients.view`.
  Permisos `clients.{view,create,update,delete}` en `RolePermissionSeeder::PERMISSIONS`.
- Validación = `App\Livewire\Forms\ClientForm` (Form object, mismo patrón que el resto del panel, NO
  FormRequest). CRUD simple → sin Service intermedio, la lógica vive en el Form.
- `Client` implementa `Auditable` + `SoftDeletes`.
- **Calendario de reparto** — el cliente puede ser: **bajo demanda** (nada) / **días de la semana**
  (`delivery_weekdays` jsonb `[1..7]` ISO, con rango opcional `schedule_starts_on`/`schedule_ends_on`;
  `schedule_ends_on` null = indefinido) / **cada N días** (`frequency_days` + `last_served_on`, lo
  anterior). Si hay `delivery_weekdays`, gana sobre `frequency_days`. Helpers en `Client`:
  `deliveryWeekdays()`, `hasWeekdaySchedule()`, `scheduleActiveOn()`, `isScheduledOn()`,
  `nextDeliveryOn()`, `isDeliveryDue()` (todos ramifican weekday vs frequency), `frequencyLabel()`
  ("L·X·V · abr.–jun." / "Quincenal" / …). `Client::WEEKDAY_LABELS` (1→L … 7→D).
- **Generación automática de paradas recurrentes** (`App\Services\RecurringStopService`): para los
  clientes con `delivery_weekdays`, crea la parada del día (`scheduled_for` = ese día,
  `delivery_type_id` = agua) — con `RecurringRouteService::findRouteDayForAutoAssign()` (2026-09-10,
  ver "Panel Administrador" más arriba) ya nace **colocada en la columna de la ruta de ese día** si
  hay una única ruta permanente candidata de ese `service_kind`; si no, nace sin ruta (`route_id =
  null`) en "Sin asignar". Así un cliente diario (p. ej. L-V) aparece solo, cada día que le toca, en
  la columna del camión correspondiente — sin que oficina tenga que arrastrarlo a mano cada vez.
  Idempotente (`stopExists` por CIF/nombre + `scheduled_for`) — y **cuenta las paradas borradas
  (`withTrashed()`)**: si oficina elimina desde el tablero la parada recurrente de un día concreto,
  esa decisión manda y NO se regenera ese día (ni en el refresco del tablero ni en el planificador);
  el calendario del cliente sigue vigente para el resto de días (2026-09-11). Se
  dispara desde `Routes\Board` al abrir/cambiar de día (`generateRecurringStops()`, guardado con
  `can('routes.update')`) y desde el comando `rutas:generar-recurrentes {fecha?}` (scheduler diario
  05:30, horizonte +14 días). **"Sin asignar" ya NO se filtra por fecha** (decisión revertida,
  2026-09-10 — antes solo se veían ahí las del día que se estaba viendo: `scheduled_for IS NULL`
  (backlog general) **OR** `scheduled_for = fecha vista`): esa columna es **independiente del día**
  que se esté viendo en el tablero, muestra siempre todo lo que sigue sin ruta
  (`RouteStop::unassigned()`), tenga o no `scheduled_for` y sea cual sea su valor.
- **"Planificar reparto"** (ficha del cliente y, desde 2026-09-13, también la fila de
  `/clientes` — mismo botón, mismo modal) abre `<x-clients.plan-modal>`: ruta (o "Sin
  asignar"), rango de fechas y días de la semana. `App\Services\ClientDeliveryPlanner::plan()`
  genera de una vez un `RouteStop` por cada día del rango que caiga en esos días de la semana,
  copiando nombre/CIF/dirección/coordenadas/litros del cliente y `delivery_type_id =
  DeliveryType::waterId()`. **Decisión explícita del usuario, dos veces confirmada**: (1) esto
  es un **lote fijo de una sola vez para el rango pedido**, NO un calendario recurrente
  permanente — no toca `clients.delivery_weekdays`/`schedule_starts_on`/`schedule_ends_on` (eso
  ya existe aparte y lo sigue generando solo `RecurringStopService` cada noche); para seguir más
  allá del rango, se vuelve a planificar. (2) el selector de ruta **permite "Sin asignar"**
  (cae al backlog del tablero, igual que el botón de siempre) en vez de ser obligatorio. Pensado
  para agilizar "Viajes": con varias rutas candidatas, `RecurringRouteService::
  findRouteDayForAutoAssign()` no puede adivinar sola cuál usar (exige exactamente 1 candidata)
  — aquí oficina la elige a mano de una vez para todo el rango. Con una ruta elegida, cada día
  usa `RecurringRouteService::ensureForDate()` (crea el `RouteDay` de esa fecha si falta, lo
  reabre si ya estaba completada) — **cada día tiene su propio `RouteDay`**, aunque cuelguen
  todos de la misma `Route` permanente (no confundir `route_stops.route_id`, que apunta a un
  `RouteDay` concreto, con el `route_id` de ese `RouteDay` hacia la `Route` permanente). Mismo
  criterio de no-duplicado que `RecurringStopService::stopExists()` (por CIF/nombre + fecha,
  incluidas paradas borradas). Rango tope 1 año (protección ante un despiste de fecha). Como SÍ
  hay `route_id` real cuando se elige ruta, `RouteStopObserver` notifica al chofer normalmente
  (una notificación por parada creada) — no se silencia, a diferencia del generador nocturno.
  Requiere `clients.update` **y** `routes.update`. Formulario compartido `App\Livewire\Forms\
  ClientPlanForm` entre `Clients\Index`/`Clients\Show` (mismo patrón que `ClientForm`).
- **"Suspender repartos" (2026-09-14)**: botón en la ficha del cliente (`Clients\Show`, junto a
  "Editar" — solo ahí, no en la fila de `/clientes`), visible si el cliente tiene calendario
  activo (`hasWeekdaySchedule()`/`frequency_days`) o alguna parada `Pending` emparejada. Pensado
  para cuando un cliente con reparto planificado por meses/un año ya no quiere el servicio: antes
  había que borrar cada `RouteStop` pendiente una a una y el calendario seguía generando paradas
  nuevas cada noche. `App\Services\ClientDeliverySuspender::suspend()` hace las dos cosas de una
  vez — apaga el calendario (`delivery_weekdays`/`frequency_days`/`schedule_starts_on`/
  `schedule_ends_on` a `null`) y cancela **todas** las `RouteStop` en estado `Pending` emparejadas
  por CIF/nombre (mismo criterio que `Client::pastStops()`), **incluidas las de hoy** (decisión
  explícita del usuario — si el cliente ya no quiere el servicio, tampoco la de hoy). Las
  completadas/falladas/canceladas son historial y no se tocan. Cancela con un `->delete()` por
  modelo (no un `whereIn(...)->delete()` de query builder) para que `RouteStopObserver` dispare
  igual que al borrar una parada a mano desde el tablero. Requiere `clients.update` **y**
  `routes.update`, mismo gate que "Planificar reparto". `wire:confirm` muestra el nº de paradas
  que se van a cancelar (`Show::pendingStopsCount`, computed).
- **El producto siempre es agua**: se eliminó `clients.default_delivery_type_id`. `DeliveryType::waterId()`
  (slug `agua`) es el que usan `planDelivery`, `Chofer\Today::addClientStop` y el generador.
- **Mapa de solo lectura en la ficha (2026-09-23)**: `Clients\Show` — petición del usuario, "un
  pequeño mapa para que el administrador pueda verificar fácilmente" la ubicación guardada. Un
  pin fijo (sin arrastre, para eso ya existe `<x-ui.geofence-map>` en el formulario de edición),
  `Alpine.data('clientLocationMap')` (`app.js`), mismos tiles ArcGIS que el resto de mapas de la
  app (`routeMap`/`deviceMap`/`attendanceLocationMap`). Se pinta en la tarjeta "Contacto y
  ubicación" debajo de las coordenadas, solo si el cliente las tiene (si no, un aviso invita a
  añadirlas desde "Editar"). `wire:ignore`, igual que el resto de mapas Leaflet de la app —
  **efecto secundario aceptado**: si se edita la dirección/coordenadas del cliente, el mapa se
  queda con el punto viejo hasta recargar/volver a entrar (no hay listener de actualización en
  caliente, a diferencia de `tank3d`'s `tanks-updated` — no se ha pedido y añadía complejidad
  para un caso de uso raro: editar coordenadas y querer verlas al instante en la misma carga).
  De paso se añadió el único campo capturado en el alta que no se mostraba en ningún sitio de la
  ficha: `last_served_on` ("Último reparto (dato manual)" en la tarjeta "Reparto habitual") —
  todos los demás ya estaban cubiertos, algunos combinados en helpers del modelo
  (`quantityLabel()`, `priceLabel()`, `frequencyLabel()`) y otros en la cabecera (tipo de
  cliente, CIF, código externo, activo/inactivo).
- **Import Access = comando, una sola vez** (decisión del usuario, NO subida por UI):
  `php artisan clientes:importar <archivo.csv> [--dry-run]`, archivo en la raíz del proyecto o ruta
  absoluta. `App\Services\ClientImporter` + `league/csv` (^9.0). Upsert por `external_ref` (con
  `withTrashed()` + `restore()`). Autodetecta delimitador `;`/`,`, mapea ~60 alias de cabecera
  ES/EN (`HEADER_MAP`), parsea números en formato español (`1.234,56`) y periodicidades textuales
  (`semanal`/`quincenal`/`mensual`). Filas inválidas se saltan y se reportan, no abortan. Plantilla
  en `docs/plantilla-clientes.csv`. El formato completo de columnas está en `docs/02` (Bloque 9).
- El seeder (`DatabaseSeeder::seedClients()`) crea ~57 clientes y **reasigna ~75% de las paradas
  del historial** a esos clientes (por `customer_name`/`customer_tax_id`) para que las fichas tengan
  histórico. Idempotente (`if (Client::query()->exists()) return`).
- `Audits::MODELS` incluye `'Cliente' => Client::class`.
- **Bug real de producción corregido (2026-09-23): `MathException`/`UniqueConstraintViolationException`
  al guardar un cliente.** Dos causas distintas en `ClientForm::save()`, ambas por no normalizar el
  input antes de validar: (1) `latitude`/`longitude` con espacios sueltos (copiar/pegar desde Google
  Maps, autocompletado) pasaban la regla `numeric` de Laravel (PHP `is_numeric()` admite espacios al
  principio/final desde PHP 8) pero reventaban el cast `decimal` de Eloquent al guardar —
  `BigDecimal::of()` (brick/math, motor real del cast) usa una regex estricta `^...\z` que NO admite
  ningún espacio, así que `save()`/`isDirty()` lanzaba `MathException` a mitad de un `update()` ya
  autorizado y validado, un 500 real sin ningún error de validación visible antes. (2) `external_ref`
  en blanco se guardaba como `''`, no `null` — mismo riesgo latente ya documentado arriba
  (`clients.external_ref` es `unique()` normal, no parcial como `users.email`): Postgres SÍ considera
  `''` un valor colisionable (a diferencia de `NULL`, del que admite varios), así que dos clientes
  guardados sin código a la vez rompían el índice único con un `UniqueConstraintViolationException`
  crudo. **Fix**: `ClientForm::save()` normaliza los tres campos (`trim()` + `'' → null`) *antes* de
  `$this->validate()`, así un valor con espacios de sobra se limpia solo (sin ida y vuelta de error)
  y un código externo en blanco pasa a `null` de verdad, evitando la colisión de raíz en vez de
  depender de que `Rule::unique` la detecte a tiempo (no lo hacía: no hay lock, dos guardados casi
  simultáneos podían pasar ambos la validación antes de que ninguno hubiera insertado todavía).
- **Aviso en el propio campo si el formato de latitud/longitud no es válido (2026-09-23)**:
  petición explícita del usuario tras el bug de arriba — quería el aviso al momento, no solo que
  `save()` se lo trague en silencio. `ClientForm::rules()` añade `regex:/^-?\d+(\.\d+)?$/` a los
  dos campos (más estricto que `numeric`: rechaza espacios, notación científica, coma decimal,
  cualquier texto — el mismo hueco que causaba el `MathException`) + mensajes en español
  (`latitude.regex`/`longitude.regex`). Los inputs pasan de `wire:model` (diferido) a
  **`wire:model.blur`** y `Clients\Index`/`Clients\Show` ganan un `updated($name)` que llama a
  `$this->validateOnly($name)` solo para `form.latitude`/`form.longitude` — el error aparece bajo
  el campo en cuanto el usuario sale de él, sin esperar a "Guardar" (ver el bug de
  `<x-ui.input>` corregido el mismo día en la sección "Sistema de diseño" — sin ese fix, esta
  función habría seguido sin verse nunca). `.blur` en vez de `.live` a propósito: con cada tecla, un
  valor a medio escribir como "-16." fallaría el regex y parpadearía en rojo mientras el usuario
  sigue tecleando. El `normalizeBlank()`/`trim()` de `save()` (ver el punto de arriba) sigue
  intacto como red de seguridad — un espacio de sobra que el usuario no llegó a corregir se limpia
  solo al guardar, el aviso en el campo es un adelanto, no un bloqueo duro. De paso,
  `lang/es/validation.php` gana `attributes.latitude`/`.longitude`/`.address` ("latitud" /
  "longitud" / "dirección") — sin ellos el mensaje por defecto de `numeric`/`between` mostraba el
  nombre de columna en crudo ("El campo longitude debe ser un número.").
- **Sugerencias de dirección al escribir en la ficha de cliente (2026-09-23)**: mismo
  `App\Services\GeocodingService` (Nominatim) que ya usaba `<x-ui.geofence-map>` (fichaje/base),
  pero en una versión ligera sin mapa ni radio — el cliente es un punto, no una geovalla, y el
  campo "Dirección" ya es el dato real que se guarda (no una caja de búsqueda aparte como en
  geofence-map). `Clients\Index::searchAddress()` / `Clients\Show::searchAddress()` (mismo patrón
  que `Users`/`Drivers`/`Maintenance\Settings`, sin permiso nuevo — proxy de solo lectura) +
  `Alpine.data('addressAutocomplete')` (`app.js`, hermano ligero de `geofenceMap`): al escribir en
  "Dirección" (`x-on:input.debounce.400ms`, mínimo 5 caracteres para no machacar la API externa en
  cada tecla) aparece un desplegable de sugerencias bajo el campo; al elegir una, rellena a la vez
  `form.address` (con el `display_name` completo de Nominatim), `form.latitude` y `form.longitude`
  — así la vía normal para fijar la ubicación de un cliente pasa a ser "escribir la dirección y
  elegir una sugerencia", no teclear las coordenadas a mano (la causa original del bug de formato
  de arriba). El campo sigue admitiendo edición manual de lat/lng por si la dirección no aparece
  en Nominatim o el pin necesita un ajuste fino.

### Pre-clientes / valoración (`App\Enums\ClientStatus`)
- Administración apunta por teléfono un posible cliente → queda como **"Pendiente valoración"**
  (`clients.status = 'prospect'`); tras consultar offline con los responsables: **Aprobar**
  (`status = 'customer'` + abre el modal de edición para completar) o **Descartar** (`forceDelete()`
  **permanente**, con `wire:confirm` — decisión del usuario, sin estado "descartado").
- Columna `clients.status` (string, **default `'customer'`**, indexada; migración
  `2026_09_07_130000_...`). Todo lo que trata a un cliente como "real" debe scopear con
  **`Client::scopeCustomers()`** (`status = customer`), NO con `->active()` (`is_active`, ortogonal).
  Ya aplicado en `Clients\Index::render()` (los prospectos no se ven salvo el filtro
  `status=prospect`) y en `Chofer\Today::clientMatches()` (`->customers()->active()`).
  `Client::isProspect()`; `Show::planDelivery()` y `Today::addClientStop()` abortan si es prospecto.
- **Alta = el mismo modal "Nuevo cliente"** con un `<x-ui.select wire:model.live="form.status">`
  "Cliente | Pendiente valoración" arriba (`x-clients.form-fields` recibe `:status` y `:editing` del
  padre; con `wire:model.live` el commit re-renderiza y el `@if ($status !== 'prospect')` colapsa el
  form a los campos de la llamada). El toggle solo aparece `@unless ($editing)`.
- Autorización: `ClientPolicy::approve` = `clients.update`; descartar = `clients.delete`. **No hay
  permiso nuevo.** `Index::approve/discard` y `Show::approve/discard`.
- **Cantidad en litros + unidad citada**: `clients.typical_quantity` **siempre en litros**;
  `clients.quantity_unit` ('L'|'m3') recuerda cómo lo dijo el cliente. En `ClientForm` NO hay prop
  `typical_quantity`: hay `$quantity_input` (número en la unidad) + `$quantity_unit`. `save()`
  multiplica ×1000 si `m3` y setea `typical_quantity` a mano (`unset($validated['quantity_input'])`);
  `setClient()` lo revierte (`/1000` si la unidad guardada es `m3`). La ficha usa
  `Client::quantityLabel()` → "3 m³ (3.000 L)". Campos de suministro nuevos: `water_type` (enum
  `WaterType` corriente/potable), `tank_distance_m` (metros), en la sección "Datos del suministro"
  del form y de la ficha, visible para prospectos y clientes.
- **Precio: tarifa fija o por litro**. `clients.price_per_liter` → **renombrado a `clients.price`**
  (migración `2026_09_07_140000_...`, `renameColumn`) + `clients.price_type` (enum `PriceType`:
  `fixed`|`per_liter`; los que ya tenían precio → `per_liter`). `save()` pone `price_type = null`
  si `price` es null. Ficha: `Client::priceLabel()` → "45,00 € (Tarifa fija)" / "0,9500 €/L".
- El **tipo de servicio** (`service_kind` Reparto/Viaje) ahora es visible **también en el alta de
  pre-cliente** (una llamada puede ser de un viaje).
- **Gotcha del componente `x-ui.select` con `placeholder`**: la `<option value="" disabled selected>`
  no se honra visualmente — el `<select>` MUESTRA la primera opción real aunque el modelo Livewire
  siga en `null` (se guarda `null`, solo el display engaña). Es pre-existente (afecta a
  `client_type`, `preferred_channel`…); `water_type` lo hereda.

### Tipo de servicio: Reparto / Viajes (`App\Enums\ServiceKind`)
- Hoy solo se opera **Reparto**; **Viajes** se gestionará más adelante. La clasificación ya existe en
  `clients.service_kind`, `routes.service_kind` y `route_stops.service_kind` (columna string, default
  `'reparto'`, migración `2026_09_07_110000_...`), casteadas al enum en los tres modelos.
- **Cliente**: selector "Tipo de servicio" en el alta/edición + filtro en `/clientes` (`#[Url] $kind`)
  + badge "Viaje" en el listado y en la ficha. `Client::scopeKind()`.
- **Tablero** (`Routes\Board`): `#[Url] $kind` (**`reparto` por defecto**, no `all` — es un filtro que
  muestra un tipo cada vez) + segmentado "Repartos | Viajes" (`setKind()`). Filtra tanto las columnas
  de ruta como el backlog "Sin asignar" por `service_kind`. Una parada nueva creada desde el tablero
  hereda el `kind` del filtro activo (`RouteStopForm::forColumn($routeId, $kind)`).
- **Ficha de ruta** (`/rutas/listado`) y **"Planificar" desde la ficha del cliente** también fijan
  `service_kind` (la parada planificada hereda el del cliente).
- Al arrastrar en el tablero no se cambia el `kind` (ambas caras del filtro son del mismo tipo, así que
  origen y destino ya coinciden). Si algún día "Viajes" necesita su propio flujo/campos, este enum es
  el punto por el que ramificar.

