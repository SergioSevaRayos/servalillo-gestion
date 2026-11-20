# Panel Administrador, Kanban de rutas y Route permanente/RouteDay (Bloques 3-4)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Panel Administrador (Bloque 3)
- 4 módulos: `/chofers`, `/camiones`, `/usuarios` (exclusivo administrador/mantenimiento), y la ficha de
  rutas en `/rutas/listado`. Todos con búsqueda+filtro+orden+paginación.
- **`/rutas/listado` es el CRUD de la ruta *permanente*** (camión+chofer+tipo de servicio, sin fecha —
  ver "Route permanente / RouteDay" más abajo). No tiene `code` propio: se muestra como
  `{truck.code} · {driver.name} · {service_kind label}`. El código con fecha (`{R|V}-{YYYYMMDD}-
  {camión}`) lo sigue generando `App\Support\RouteCode::build()`, pero ahora solo para el `RouteDay`
  de cada día (`RecurringRouteService`), no para la ficha permanente.
- **Chofers vs Usuarios**: un chofer es un `User` (rol `chofer`) + `Driver`. Se crea/edita **solo** desde
  `/chofers` (transacción User+Driver en `DriverForm::save()`). `/usuarios` gestiona exclusivamente
  `administrador`/`mantenimiento` y nunca lista ni toca chofers — evita dos pantallas escribiendo la
  misma fila de `users`. Coherente con eso, **el chofer no puede borrar su propia cuenta desde
  "Mi perfil"**: la tarjeta "Eliminar cuenta" de `profile.blade.php` va dentro de
  `@unless (auth()->user()->isDriver())` y el `deleteUser()` de `livewire/profile/delete-user-form`
  hace `abort_if(Auth::user()->isDriver(), 403)` (los otros bloques del perfil —nombre, email,
  contraseña— sí los usa). El gestor sí puede autoborrarse (el Breeze de serie).
- **Patrón de modal** (`<x-modal name="...">`): abrir/cerrar SIEMPRE con
  `$this->dispatch('open-modal'|'close-modal', 'nombre-del-modal')` desde el componente Livewire.
  Nunca uses un `:show="$propiedadLivewire"` para controlar apertura tras el primer render — Alpine
  conserva su estado (`show`) a través de los re-renders/morph de Livewire, así que cambiar esa prop
  no reabre ni cierra nada después del primer paint. Esto es el mismo mecanismo que ya usaba Breeze
  para el modal de borrar cuenta; todos los módulos de gestión lo siguen.
- Borrado = `wire:confirm="..."` (nativo de Livewire 3) + `$this->dispatch('toast', message:, variant:)`.
- **`users.email` tiene un índice único parcial** (`WHERE deleted_at IS NULL`, migración
  `2026_09_10_130000_...`), no un `unique()` normal — con `SoftDeletes` en `User`, un `unique()` a
  secas bloquea ese email **para siempre** aunque la cuenta esté borrada (bug real en producción:
  borrar un usuario por error dejaba su email inservible). `UserForm`/`DriverForm` ignoran
  `deleted_at` al validar (`Rule::unique(...)->whereNull('deleted_at')`) para que ambas capas
  coincidan. Mismo riesgo latente sin arreglar (a valorar si molesta) en `drivers.employee_code` y
  `clients.external_ref`, que sí son `unique()` normales sobre modelos con `SoftDeletes`.
- **`<x-ui.password-input>`** (usado en `/usuarios` y `/chofers`): botón de ojo mostrar/ocultar +
  botón opcional `suggest` que genera una contraseña de 12 caracteres (letras+dígitos garantizados,
  cumple `Password::defaults()`: mín. 10, letras y números — `AppServiceProvider::boot()`). Todo en
  JS local del componente (`x-data` inline, sin registrar nada en `app.js`); el botón "generar"
  escribe en el `<input>` y dispara un evento `input` para que `wire:model` (diferido) lo recoja,
  sin tocar `$wire` directamente.
- Helpers de test centralizados en `tests/Pest.php` (no los dupliques en archivos individuales):
  `makeUser(string $role): User`; `makeRoute(string $date = '2026-09-10'): RouteDay` (crea la ruta
  *permanente* con truck+driver y su `RouteDay` de esa fecha — lo que necesita casi todo test que
  monta paradas/jornada); `makePermanentRoute(): Route` (solo la ficha permanente, sin ningún día);
  `makeRouteDay(Route $route, string $date = '2026-09-10'): RouteDay` (un día más de una ruta
  permanente ya creada, para tests con varios días de la misma ruta).

### Tablero Kanban de rutas (Bloque 4)
- **`/rutas` (`routes.board`) es ahora la vista principal** de "Rutas" — `App\Livewire\Routes\Board`.
  Columnas = **`RouteDay`** de la fecha seleccionada (`#[Url] $date`) + columna fija **"Sin asignar"**
  (`route_stops.route_id IS NULL`, backlog global sin filtrar por fecha). Tarjetas = `route_stops`,
  vía `<x-routes.stop-card>`. La ficha CRUD del Bloque 3 sigue viva en `/rutas/listado` (`routes.index`,
  ahora sobre la ruta *permanente* — ver "Route permanente / RouteDay").
- `route_stops.route_id` es **nullable** desde la migración `2026_09_06_090000_...` (antes era
  obligatorio) y su FK es `nullOnDelete()` (antes `cascadeOnDelete()`); apunta a `route_days`.
- **Bug real de producción corregido (2026-09-14): la tarjeta de una parada completada mostraba
  los litros PREVISTOS (`planned_quantity`), no los realmente servidos.** `<x-routes.stop-card>`
  pintaba siempre `planned_quantity` sin mirar el estado — para una parada `Completed` mostraba
  el pedido, no la entrega real (podían no coincidir). Fix: igual que `<x-chofer.stop-card>` ya
  hacía, si la parada está `Completed` y tiene `delivered_quantity`, se muestra ese valor (en
  verde, con sufijo " L"); en cualquier otro caso (pendiente, sin completar todavía) sigue
  mostrando `planned_quantity` como antes.
- **Buscar cliente y meterlo en "Sin asignar" (2026-09-11)**: la columna "Sin asignar" tiene un
  botón **"Buscar cliente"** (además del "+ Añadir parada manual") → modal `client-search` con
  buscador incremental (`Board::clientMatches`, `Client::customers()->active()->kind($this->kind)
  ->search()`, mín. 2 caracteres, filtrado por el filtro Repartos/Viajes activo). Elegir un
  resultado llama a `Board::addClientToBacklog(Client)` que crea la `RouteStop` con `route_id = null`
  copiando los datos del cliente — **mismo copiado que `Clients\Show::planDelivery()`** (nombre, CIF,
  dirección, coords, contacto, `typical_quantity`, `delivery_type = agua`). Autorización:
  `create` sobre `RouteStop` + permiso `routes.update`. Oficina solo tiene que arrastrarlo luego a
  la columna del camión. El `RouteStopObserver` no notifica (route_id null).
- **`RouteDay` es `SoftDeletes`**, así que el FK `nullOnDelete` NO se dispara al borrar desde la UI —
  pero hoy no hay ninguna acción de UI que borre un `RouteDay` suelto (solo la ruta *permanente* se
  borra desde `/rutas/listado`, y eso no toca sus `RouteDay` — ver esa sección). Este dato queda por
  si algún día se añade un "eliminar el día" en el tablero: habría que repetir el patrón que tenía
  el `Routes\Index::delete()` de antes de la reforma (sacar las pendientes a "Sin asignar", las
  cerradas se van con la fila).
- Crear/editar una parada (`App\Livewire\Forms\RouteStopForm`) es el primer sitio de la app que **usa de
  verdad** el modelo flexible de repartos: al elegir un `delivery_type_id` se renderizan dinámicamente
  los campos de su `field_schema` y se validan con `DeliveryTypeSchemaValidator` antes de guardarlos en
  `route_stops.data`. Si tocas el editor de tipos de reparto en el futuro, prueba también este formulario.
  - El modal `stop-form` es **ancho** (`<x-modal max-width="3xl">`) con rejilla `grid-cols-1
    sm:grid-cols-2 lg:grid-cols-3` y los campos cortos agrupados por fila (Cliente/Dirección,
    Contacto/Teléfono/Estado, Cantidad/Tipo de reparto) para que quepa sin scroll en escritorio —
    ver la "Regla de modales" en la sección del sistema de diseño. `<x-modal>` acepta
    `sm|md|lg|xl|2xl|3xl|4xl`.
  - **Al EDITAR** solo se tocan los datos del servicio (`status`, `planned_quantity`,
    `delivery_type_id`, `scheduled_for`, `data`): la identidad del cliente (`customer_name`,
    `address`, `contact_*`, `service_kind`) sale como bloque de **solo lectura** y
    `RouteStopForm::save()` **no la persiste** aunque llegue en el request (la ficha del cliente es
    su único punto de edición). Al **CREAR** sí se piden todos los campos.
  - **`scheduled_for`** (2026-09-10): editable desde el propio modal (`<x-ui.date-input>`), tanto al
    crear como al editar. Antes solo lo ponían procesos automáticos (`RecurringStopService`,
    `StopActionForm::rescheduleStop`); ahora oficina también puede fijarlo a mano — sobre todo para
    que `autoAssignToRouteDay()` (justo abajo) sepa a qué día colocar la parada si acaba entrando
    en una ruta. **No cambia si la parada se ve o no en "Sin asignar"** (esa columna ya no filtra
    por fecha, ver "Gestión de clientes" más abajo) — solo importa mientras siga sin ruta.
    **`RouteStopForm::autoAssignToRouteDay()`**: si tras guardar la parada sigue sin ruta
    (`route_id = null`) y se le ha puesto fecha, delega en
    **`RecurringRouteService::findRouteDayForAutoAssign(serviceKind, fecha)`** — punto único
    compartido con `RecurringStopService` (ver "Gestión de clientes" más abajo). Busca entre las
    rutas *permanentes* del mismo `service_kind` vigentes esa fecha: con **una sola candidata**,
    garantiza su `RouteDay` (`ensureForDate()`, reabriéndolo con `RouteDay::reopenIfCompleted()`
    si hacía falta) y la parada se coloca sola ahí; con cero o varias, no hay forma de adivinar
    cuál y devuelve `null` — la parada se queda en "Sin asignar" (ya con la fecha puesta) para que
    oficina la arrastre a mano. Poner fecha, en el caso normal de un solo camión activo de ese
    tipo, sustituye entonces al arrastre manual.
- **Drag & drop = SortableJS** (`npm install sortablejs`, importado en `resources/js/app.js`,
  función `initKanbanColumns`), no el plugin `@alpinejs/sort` — se descartó por no poder verificar con
  certeza su API exacta de arrastre multi-columna sin acceso a la documentación en vivo; SortableJS es
  inequívoco y es literalmente el que pedía el brief original.
  - Cada columna es `<ul data-stop-list data-route-id="{id}">` (sin el atributo = "Sin asignar").
    `onEnd` de Sortable arma un `CustomEvent('stops-reordered')` en `window` con las listas de
    `data-stop-id` de origen y destino; el componente lo captura con
    `x-on:stops-reordered.window="$wire.call('reorderStops', ...)"` y persiste posiciones + `route_id`.
  - **No hace falta reinicializar Sortable tras cada respuesta de Livewire**: los `<li>` llevan `wire:key`
    estable y el orden que devuelve el servidor coincide con el que dejó el arrastre en el DOM (se le
    pasó ese mismo orden), así que el morph no toca esos nodos. `initKanbanColumns` igualmente se vuelve
    a llamar en `livewire:navigated` y en `Livewire.hook('morph.updated', ...)` por si aparecen columnas
    nuevas (cambio de fecha), con una guarda `list._sortable` para no doble-inicializar.
  - Al llamar `$this->form->save(...)` desde un componente Livewire, los `Form` objects **no** reciben
    inyección de dependencias automática como un controlador — si el método `save()` pide un servicio
    (aquí `DeliveryTypeSchemaValidator`), resuélvelo a mano: `$this->form->save(app(Servicio::class))`.
- Móvil = **una columna a la vez con swipe** (decisión validada en `docs/03-ux-panel-rutas-kanban.md`):
  contenedor con `snap-x snap-mandatory`, columnas `snap-start` a ~88vw, más una barra de pestañas encima
  que hace `scrollIntoView` a la columna elegida. En escritorio, columnas de 320px en fila sin snap.
- Al testear `Board` con Livewire: `Livewire::test(Board::class, ['date' => '...'])` **no** funciona
  porque `mount()` no declara un parámetro `$date` (Livewire solo pasa por `mount()` lo que su firma
  acepta) — usa `->test(Board::class)->set('date', '...')` en su lugar.
- **Solo las paradas en estado `Pending` se pueden arrastrar** (no tiene sentido reasignar una ya
  completada/fallida). Doble barrera: `filter: '[data-draggable="false"]'` en SortableJS (cliente,
  `data-draggable` lo pone `<x-routes.stop-card>`) + comprobación real en `Board::reorderStops()`
  (servidor: una parada cerrada que cambiaría de ruta → 422). Las tarjetas no arrastrables se pintan
  con un **tono apagado** (fondo grisáceo + `grayscale-[0.3]`, sin candado ni icono) — decisión
  explícita del usuario.
  - **Las paradas cerradas quedan FIJAS: ni se cogen ni se desplazan.** `onMove` de Sortable devuelve
    `false` cuando el vecino afectado (`evt.related`) es `[data-draggable="false"]` → la tarjeta
    cerrada actúa como muro y no se anima apartándose para hacer hueco (antes se veía el salto).
    Segunda barrera en servidor: `Board::reindexColumn()` reconstruye la columna a partir del orden
    PREVIO (`position`) — cada cerrada conserva su sitio relativo al flujo de pendientes, las
    pendientes se reparten alrededor en el orden del arrastre (mismo criterio que
    `RouteOptimizer::optimize()`).
- **`RouteStopStatus::InProgress` ("En curso") se eliminó del todo** (a valorar si se reintroduce más
  adelante). No confundir con `RouteStatus::InProgress`, que es el estado de la *ruta* completa y sigue
  existiendo — son enums distintos para conceptos distintos.
- **Nunca uses la clase `transition` genérica de Tailwind en una tarjeta arrastrable con SortableJS** —
  incluye `transform` por defecto y compite con la animación FLIP que Sortable usa para que las tarjetas
  vecinas "hagan hueco" a la que se está soltando (se ve a tirones). Usa la utilidad específica que
  necesites (`transition-shadow`, `transition-colors`…), nunca la genérica, en cualquier `<li>` de una
  lista `data-stop-list` (o de un futuro tablero equivalente en el Bloque 7).
- **`forceFallback: true` es obligatorio para que el "hacer hueco" se vea bien.** Sin él, Sortable usa
  el drag-and-drop nativo del navegador: el "fantasma" que se arrastra es una captura que pinta el propio
  navegador (nuestras `ghostClass`/`chosenClass`/`dragClass` casi no se notan) y el `dragover` nativo
  dispara con menos frecuencia que un `pointermove`, así que el reflow de las tarjetas vecinas se ve a
  saltos — más aún al cruzar de una columna a otra. `forceFallback` hace que Sortable simule el arrastre
  entero con JS/CSS propios (control total del estilo y animación fluida). Junto con `fallbackOnBody: true`
  (para que la tarjeta arrastrada no se recorte contra el `overflow-y-auto` de la columna) y
  `fallbackTolerance: 3`. Si algún día el drag&drop "funciona pero se ve a tirones", esto es lo primero
  a revisar.
- **`chosenClass`/`ghostClass`/`dragClass` de SortableJS tienen que ser UNA sola clase CSS, nunca varias
  separadas por espacio.** Sortable hace `el.classList.add(options.chosenClass)` con el valor tal cual;
  `classList` lanza `InvalidCharacterError` si el string tiene espacios (rompía el drag entero: "aparecen
  un montón de errores y no funciona"). Si necesitas varios estilos a la vez, agrúpalos en una clase
  propia dentro de `@layer components` (ver `.stop-card-chosen` en `resources/css/app.css`) y pasa esa
  única clase a la opción de Sortable.
- **Cualquier clase de Tailwind que solo aparezca dentro de una cadena en un `.js` no se compila** a
  menos que `resources/js/**/*.js` esté en el `content` de `tailwind.config.js` (ya está añadido). Sin
  eso, Tailwind la considera "no usada" y la poda del CSS final sin avisar — la clase existe en el HTML
  en tiempo de ejecución pero no tiene ninguna regla asociada, así que no se ve nada (ni error, esto es
  lo insidioso). Si añades una clase nueva solo referenciada desde JS, o bien confirma que ya aparece
  también en algún `.blade.php`, o recuerda que ahora sí se escanea `resources/js/`.
- Comportamiento Trello real: el tablero no crece verticalmente. Cada columna es `flex flex-col` con
  altura fija en móvil (`h-[65vh]`) y, en escritorio, **toda la altura disponible hasta el borde inferior de la ventana** (2026-11-20: la raíz del tablero es `md:h-[calc(100vh-2rem)] md:-mb-8 md:flex md:flex-col` — resta el `py-8` superior de `<main>` y cancela el inferior —, la barra de filtros `md:shrink-0`, el contenedor de columnas `md:flex-1 md:min-h-0` y cada columna `md:h-auto` para que se estire; así los botones «+ Añadir parada» y la barra de scroll horizontal quedan pegados abajo y la página no hace scroll), cabecera y botón "+ Añadir parada" con `shrink-0`,
  y la `<ul data-stop-list>` con `flex-1 min-h-0 overflow-y-auto` (el `min-h-0` es imprescindible: sin
  él, un hijo flex no encoge por debajo de su contenido y `overflow-y-auto` no llega a activarse nunca).
  El scroll para moverse entre columnas sigue siendo horizontal, en el contenedor de columnas.
- La `<ul data-stop-list>` lleva `px-1.5 -mx-1.5` (padding + margen negativo que se cancelan en el borde
  exterior, dejando la lista alineada con la cabecera/botón de la columna) para que el `ring`/sombra de
  la tarjeta "cogida" tenga sitio donde respirar sin recortarse contra el borde de scroll de la columna.
  Además, `.stop-card-chosen` usa **`ring-inset`** (el anillo se dibuja hacia dentro del borde, no hacia
  fuera) — combinar ambas cosas es lo que evita el "perfil izquierdo recortado" al arrastrar una tarjeta.
- `.themed-scrollbar` (`resources/css/app.css`): clase reutilizable para dar a cualquier contenedor con
  scroll propio una barra fina a juego con el sistema de diseño (claro/oscuro), en vez de la nativa del
  navegador. Úsala en cualquier `overflow-y-auto`/`overflow-x-auto` nuevo antes de dejar la del sistema.

### Route permanente / RouteDay (2026-09-10 — reemplaza el diseño anterior de "asignación permanente")

- **Historia (dos iteraciones el mismo día):** primero se probó una tabla `truck_assignments` aparte
  (camión+chofer, sin fecha) que generaba sola una fila de `routes` (entonces = "un día") cada
  madrugada. El usuario lo vio funcionar en producción (`/rutas/listado` con 6 filas
  `R-20260910-C-01`…`R-20260915-C-01` para el mismo camión+chofer) y pidió dar marcha atrás:
  **"una ruta tiene que ser una única fila, y aparece todos los días tenga viajes o no"**, más una
  **herramienta para ver el resumen de un día concreto**. Se rediseñó invirtiendo qué tabla es "la
  ruta": ya no se crea una `TruckAssignment` aparte — la propia `Route` pasa a ser la ficha permanente,
  y lo que antes era `Route` (una fila por camión+día) pasa a ser `RouteDay`.
- **`App\Models\Route`** = la ficha permanente: `truck_id`, `driver_id`, `service_kind`, `valid_from`,
  `valid_until` (nullable = sin fin), `name`, `notes`, `created_by`, `SoftDeletes`. **Sin `code`
  propio** (se muestra como `{truck.code} · {driver.name} · {service_kind label}` — un código con
  fecha no tiene sentido en una ficha sin fecha). `Route::overlaps(column, id, from, until, ignoreId)`
  es el helper estático de solape de fechas (trata `valid_until = null` como "sin fin"), usado por
  `RouteForm` para rechazar dos rutas solapadas del mismo camión **o** del mismo chofer — así se seguía
  cumpliendo "1 camión = 1 ruta a la vez" sin depender de una fecha. CRUD en `/rutas/listado`
  (`App\Livewire\Routes\Index` + `RouteForm`), con **"Finalizar"** (fija `valid_until = hoy` sin abrir
  el modal). Borrar una `Route` la deja `SoftDeletes` pero **no toca su historial de `RouteDay`** —
  solo deja de generar días nuevos a partir de hoy.
- **`App\Models\RouteDay`** (antes `App\Models\Route`, tabla `route_days`) = la actividad de una ruta
  permanente **un día concreto**: paradas, jornada (litros inicio/fin), GPS, estado — todo lo que antes
  vivía en `Route` sigue igual aquí (`code`, `route_date`, `status`, `started_at`/`completed_at`,
  `liter_meter_*`, `deliveredLiters()`/`literMeterExpected()`/`literDiscrepancy()`, `scopeForDate()`,
  `scopeOperational()`). Tiene **`route_id`** (FK a la `Route` permanente que lo generó,
  `restrictOnDelete` — no se puede borrar en duro una ruta con historial). **`route_stops.route_id`,
  `odometer_readings.route_id` y `gps_positions.route_id` NO cambiaron de nombre de columna ni de
  relación** (`RouteStop::route()`, etc. — siguen llamándose igual): solo cambió a qué modelo apunta
  esa relación (`RouteDay`, no `Route`). Índice único `(route_id, route_date)` — sustituye al viejo
  `(truck_id, route_date)`.
- **`App\Services\RecurringRouteService`** sigue el mismo concepto que antes (y que `RecurringStopService`,
  paradas recurrentes, Bloque 9): `generateForDate`/`generateHorizon(14)`, aditivo e idempotente
  (`RouteDay::firstOrCreate(['route_id'=>, 'route_date'=>], …)` — **nunca pisa un `RouteDay` ya creado o
  editado a mano** para esa ruta y esa fecha). Solo cambió el objetivo: antes creaba `Route` a partir de
  `TruckAssignment`, ahora crea `RouteDay` a partir de `Route`. Mismo comando `rutas:generar-rutas
  {fecha?}` (scheduler diario 05:25) + disparo desde `Routes\Board` al cambiar de fecha. Días generados:
  `status = Published`, `service_kind` = el de la ruta permanente. Si se borra la `Route` después, los
  `RouteDay` ya generados no se tocan.
  - **Bug real de producción corregido (2026-09-25): cambiar el chofer/camión de la ruta permanente
    desde `/rutas/listado` no se reflejaba en el tablero (`/rutas`) ni en "Mi ruta" del chofer
    nuevo.** Como `firstOrCreate()` solo copia `driver_id`/`truck_id` de la `Route` a cada `RouteDay`
    **una vez, al crearlo**, editar la `Route` después nunca tocaba los `RouteDay` ya generados —
    el chofer nuevo ni siquiera veía la ruta en su propia web (`Chofer\Today` filtra por
    `route_days.driver_id`) hasta el día siguiente (cuando `RecurringRouteService` genera un
    `RouteDay` nuevo con los datos ya correctos), y mientras tanto el chofer viejo seguía viendo y
    pudiendo operar una ruta que ya no era suya. Reportado por el usuario, que lo diagnosticó
    correctamente él mismo. **Fix**: `RouteForm::save()` detecta con `wasChanged(['driver_id',
    'truck_id'])` si de verdad cambió alguno de los dos tras el `update()`, y si es así llama a
    **`RecurringRouteService::syncUpcomingRouteDays(Route, $previousDriverId)`** (nueva) — propaga
    el chofer/camión actuales de la ruta a sus `RouteDay` de **hoy y futuros que no estén ya
    `Completed`/`Cancelled`** (el historial cerrado no se toca, mismo criterio que "eliminar una
    ruta permanente no toca el historial"). Si cambió el chofer, además reasigna su `Device` (GPS)
    al nuevo — mismo patrón (y misma guarda de "no le robes el dispositivo a quien ya tiene el
    suyo") que la reasignación manual de un día suelto que ya existía en `Routes\History::
    reassignDriver()`.
- `App\Support\RouteCode::build()` — fórmula del código (`{R|V}-{Ymd}-{camión}`), ahora usada **solo**
  por `RecurringRouteService` (y `ensureForDate()`, red de seguridad) para el `code` de cada `RouteDay`
  — la `Route` permanente no tiene código.
- **Herramienta nueva — "Historial"** (`App\Livewire\Routes\History`, `/rutas/{route}/historial`,
  enlazada desde cada fila de `/rutas/listado`): lista paginada de los `RouteDay` de esa ruta permanente
  (fecha, estado, paradas completadas/falladas/pendientes, litros repartidos vs. contador inicio→fin,
  horario de jornada), con filtro de rango de fechas. "Ver detalle" abre un modal con la lista de
  paradas de ese día (enlace al PDF del albarán si lo tiene); "Ver recorrido" reutiliza
  `RouteGeometry::payloadFor()` + `<x-route-map-modal>` (misma infra que el tablero) para el mapa de
  ese día si hay GPS/paradas con coordenadas. Autorización = `RoutePolicy::view` (la misma de la ficha
  permanente); `RouteDayPolicy` gobierna el día individual dentro (`viewDay`/`showDayMap`).
  - **"Ver detalle" ampliado (2026-09-25)**: además de la lista de paradas, el modal muestra ahora
    (1) el estado del contador de la jornada justo bajo la fecha — mismo texto/formato que la
    cabecera de columna del tablero ("Contador: inicio L · esperado L" en curso, "inicio → fin L"
    cerrada) más el aviso ámbar de `liter_discrepancy_note` si lo hubo; y (2) los datos de cada
    reparto por parada: litros entregados, aviso "No pasa por el contador" si aplica
    (`counted_in_meter`), y los campos flexibles del tipo de reparto (`deliveryType->fields()`
    contra `stop->data`, p. ej. "Tipo de depósito: Aljibe") para las completadas; el motivo para
    las falladas/canceladas (`failure_reason`). No hizo falta tocar `History.php` — `viewingDay()`
    ya cargaba `stops.deliveryType` de antes, todo el cambio fue de plantilla.
- **Cambiar el estado del día a mano (2026-09-10)**: el badge de estado del `RouteDay` es un botón
  (para quien tiene `routes.update`) tanto en la cabecera de columna del tablero como en la fila del
  Historial → abre `<x-routes.status-picker-modal>` (compartido) con los 5 `RouteStatus`. `Board::
  setStatus()` / `History::setStatus()` → **`RouteDay::changeStatus(RouteStatus $to)`**: si el día
  **sale** de `Completed` deshace el cierre de jornada (borra `completed_at`/`liter_meter_end`/la
  nota de descuadre y revierte `trucks.liter_meter` a la lectura de inicio) — misma lógica que
  `reopenIfCompleted()`, que ahora delega en `changeStatus(InProgress)`. Sirve para reabrir una
  ruta que el chofer cerró por error o cancelar la de un día que no salió.
- **Reparto de las Policies**: `RoutePolicy` (permanente) se quedó con `viewAny/view/create/update/
  delete/restore`. Lo que antes eran acciones "del día" (`reorderStops`, `optimizeOwn`, `operate`)
  pasó a **`App\Policies\RouteDayPolicy`** (auto-descubierta, sobre `RouteDay`) — mismo criterio de
  permiso + `owns()` que antes.
- **Migración de datos** (`2026_09_10_160000_redefine_route_as_permanent_entity.php`, un solo fichero):
  quita las FK de `route_stops`/`odometer_readings`/`gps_positions` → renombra `routes` a `route_days`
  → renombra `truck_assignments` a `routes` (+ añade `name`/`notes`/`service_kind`) → añade
  `route_days.route_id` → **backfill**: empareja cada `route_days` huérfano con la `routes` de su mismo
  `truck_id` cuyo `[valid_from, valid_until]` cubra esa fecha; si ninguna cubre (instalaciones con
  historial suelto sin asignación permanente detrás), crea automáticamente una ruta "histórica" por
  cada `(truck_id, driver_id)` distinto entre los huérfanos restantes → NOT NULL + FK + índice único
  nuevo → vuelve a crear las FK de las tres tablas hacia `route_days`. Si tocas esta zona del modelo de
  datos otra vez, este es el patrón a seguir (renombrar tablas + columna puente + backfill en la misma
  migración, no en dos).

