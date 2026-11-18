# Web operativa del Chofer (Bloque 7)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Web operativa del Chofer (Bloque 7)
- **`/chofer/ruta` (`chofer.today`) es `App\Livewire\Chofer\Today`** (ya no un placeholder). Muestra
  **la ruta del chofer para un día** (`route_days` donde `driver_id` = su `driver->id` y `route_date` =
  `#[Url] $date`, def. hoy) como una columna de paradas. Mobile-first, `.surface`, **nunca `.glass`**.
- **Selector de día = carrusel "coverflow" sobre scroll nativo** (`.day-carousel*` en `app.css`,
  `Alpine.data('dayCarousel')` en `app.js`). El día en foco va grande y centrado; los vecinos, cada
  vez más pequeños, girados (`rotateY`) y difuminados. El **"imán"** (encajar solo en el día más
  centrado) lo pone el navegador: el contenedor es un `overflow-x-auto` con `scroll-snap-type: x
  mandatory` y cada día `scroll-snap-align: center` (**mismo mecanismo que el dial de litros de
  `digitWheel`**). Hay dos `.day-carousel__spacer` (107px) a los lados para que el primer/último día
  puedan llegar al centro. Se mueve arrastrando (táctil), con la rueda del ratón encima, con las
  flechas (`nudge(±1)` → `scrollIntoView({inline:'center'})`) o tocando un día (`tap(iso)`, idem).
  - `paint()` (rAF en cada evento `scroll`) recalcula por día la distancia al centro del scroll y
    fija `transform`/`opacity`/`z-index` inline + la clase `.is-focus`. **No** hay `translateX`: los
    días fluyen con el scroll, solo se les gira/escala.
  - `settle()` (debounce 140ms tras el último `scroll`) mira qué día quedó centrado (`nearestEl()`)
    y, si cambió, llama a `$wire.selectDay(iso)` **una sola vez** (recarga la ruta + URL). No hace
    falta silenciar el `settle` de un reposicionamiento programático: si el día centrado ya es
    `focusIso`, `settle()` no reenvía nada.
  - La lista renderiza ±14 días alrededor de `center` (ancla). `center` solo se mueve al recolocar
    ("Hoy", carga inicial) o al **re-anclar** cuando el foco se acerca a menos de 4 días del borde
    de la lista (`settle()` hace `center = iso` + `recenter(false)`, sin smooth, invisible).
  - El `<div x-data="dayCarousel(...)">` lleva **`wire:ignore`** — es imprescindible: sin él, `initial`
    (`@js($this->date)`) cambia en cada commit, Livewire re-morfea el atributo `x-data` y Alpine
    reinstancia el componente perdiendo su estado y la posición de scroll.
  - El servidor sigue siendo la fuente de verdad de `date`: `$wire.$watch('date', …)` recoloca el
    carrusel si cambia por fuera (botón "Hoy" del header, que hace `wire:click="goToday"`).
  - `shiftDay(±n)` sigue existiendo en el componente (API), pero el carrusel usa `selectDay`.
  - **OJO:** `selectDay`/`goToday` — los nombres de método en PHP son *case-insensitive*,
    `goToDay`/`goToday` colisionan.
- **Solo se puede operar** (empezar/terminar jornada, cerrar paradas) la ruta de **hoy** o una que
  quedó `InProgress` (cerrar la de anoche). `#[Computed] operable()` lo decide; `authorizeRoute()` y
  las tarjetas `disabled` lo aplican. Otros días = solo lectura.
- **Ciclo de jornada** (lo controla el chofer, no el admin) — se anota **solo el contador de litros**,
  los km NO se piden en este flujo (`OdometerService` eliminado; `odometer_readings` sigue existiendo,
  solo lo llena el seeder para el histórico de km por camión del panel):
  - "Empezar jornada" → modal con la lectura del **contador de litros** (ruleta `<x-ui.digit-wheel
    unit="L">`, prefill `trucks.liter_meter`) → ruta pasa a `InProgress`. Las paradas no se operan
    hasta empezar (`guardStarted()` + tarjetas `disabled`).
  - "Terminar jornada" → lectura del contador ahora → ruta pasa a `Completed` y `trucks.liter_meter`
    = esa lectura.
  - `validateMeter()` valida: entero ≥ 0, inicio ≥ `trucks.liter_meter`, fin ≥ inicio.
- **Cierre de parada** (`App\Livewire\Forms\StopActionForm`): resultado = `completed` / `failed` /
  `skipped` (el enum `RouteStopStatus::Skipped` conserva el valor `'skipped'` en BD pero se
  **etiqueta "Cancelada"** en toda la UI). `completed` pide litros entregados + renderiza los campos
  del `field_schema` del tipo de reparto (mismo patrón que el editor del Kanban) y los valida con
  `DeliveryTypeSchemaValidator`. `failed`/`skipped` piden motivo (va a `failure_reason`). Se puede
  "Reabrir" una parada cerrada.
  **NO toca `delivery_notes`** — el albarán (registro, firma, PDF, envío) es entero del Bloque 8.
  `completed_at` solo se rellena en `completed` (coherente con el seeder y `FleetStatsService`).
  - **Reprogramar** (`failed`/`skipped` + campo `reschedule_on`, fecha futura): la parada actual
    queda cerrada (con "· Reprogramada para dd/mm/yyyy" en el motivo) y `StopActionForm::rescheduleStop()`
    **crea una parada nueva Pendiente** para esa fecha con `rescheduled_by` = el chofer y
    `scheduled_for` = la fecha. **Decisión revertida (2026-09-10):** antes iba siempre a "Sin
    asignar" para que oficina la revisara y colocara a mano; con la ruta permanente ya generando
    sola el `RouteDay` de cada día, ahora **se coloca directamente en la columna de la ruta del
    propio chofer** esa fecha — `$stop->route->route` (la `Route` permanente detrás de la
    `RouteDay` de hoy) + `RecurringRouteService::ensureForDate()` (crea el `RouteDay` de esa fecha
    si aún no existía, o reutiliza el que ya hubiera — idempotente). Si por lo que sea la parada no
    tiene una ruta permanente detrás (dato huérfano), cae a "Sin asignar" como red de seguridad. La
    tarjeta **"Reprogramadas para este día"** (`Today::rescheduledForDay`, solo lectura: `route_id
    IS NULL` + `scheduled_for` + `rescheduled_by = auth`) sigue existiendo para ese caso residual,
    pero en el flujo normal la parada reprogramada ya aparece como una pendiente más de la ruta.
    **Indicador "↻ Reprogramada" en la tarjeta del día de destino (2026-09-16)**: en el flujo
    normal, la nueva parada pendiente se veía en la columna del día al que se movió igual que
    cualquier otra parada nueva — no había forma de distinguir, a simple vista, que venía de un
    fallo/cancelación de otro día. `RouteStop::wasRescheduled()` (`rescheduled_by !== null`) +
    badge ámbar en `<x-routes.stop-card>` (tablero) y `<x-chofer.stop-card>` (web del chofer), justo
    debajo del nombre/estado. La tarjeta **"Reprogramadas para este día"** de arriba no lo necesita
    (su propia cabecera ya lo deja claro) — el indicador es solo para cuando la parada ya está
    colocada en la columna de una ruta, que es el caso normal desde la decisión de 2026-09-10.
- **Sincronización admin ↔ chofer = `wire:poll`** (no websockets): la web del chofer refresca cada
  **15 s** (`wire:poll.15s` en la raíz de `livewire.chofer.today`), el tablero cada **45 s**
  (`wire:poll.45s` en `livewire.routes.board`). Así lo que cambia oficina le aparece al chofer solo y
  viceversa, sin recargar. `wire:poll` se salta el ciclo si hay props "sucias" (formulario abierto en
  el tablero), lo que evita pisar la edición. Push instantáneo con Reverb queda como mejora futura
  (la infra está en `.env.example`/compose pero Echo no está cableado en `app.js`).
  - **Recuperación de sesión caducada en el poll (2026-09-10):** Livewire ya pausa el poll solo
    mientras la pestaña está en segundo plano (y sigue el `setInterval` corriendo por debajo, sin
    gap al volver) — pero un móvil que pasa horas con la pantalla bloqueada puede dejar la
    sesión/CSRF caducar de verdad, y entonces cada tick del poll falla **en silencio** (419/401):
    el chofer se queda viendo datos congelados sin ningún aviso ("no cuadra con lo que ve
    oficina"). `Livewire.hook('request', ({ fail }) => fail(({ status }) => …))` en `app.js`:
    ante un 419/401 de **cualquier** petición Livewire (poll o acción), `window.location.reload()`
    — reautentica (a login si hace falta) y trae el estado real desde cero, en vez de reintentar
    contra una sesión que ya no vale.
- Autorización: `RouteDayPolicy::operate` y `RouteStopPolicy::complete` (permiso + `owns()`), ya existían.
- `<x-chofer.stop-card>` es la tarjeta táctil del chofer (grande, sin drag), distinta de
  `<x-routes.stop-card>` (Kanban del admin).
- **Navegar con Google Maps** (`App\Support\GoogleMaps`, helper estático puro): botón **"Seguir
  ruta en Google Maps"** (`Today::navRouteUrl` computed → `GoogleMaps::directionsUrl` de las
  pendientes con coordenadas, orden `position`) + un **icono de navegación por parada** en la fila
  + enlace **"Cómo llegar"** en el modal de la parada + botón **"Abrir en Google Maps"** en el modal
  "Ver recorrido" (JS `googleMapsDirectionsUrl` en `app.js`, sirve chofer y tablero). URL:
  `maps/dir/?api=1&travelmode=driving&destination=…&waypoints=…%7C…` **sin `origin`** (Google usa el
  GPS del móvil = el camión); destino = última parada, resto = waypoints (máx **9**, se cortan);
  coords a 6 decimales; sin API key.
- **Reordenar a mano**: cada parada **pendiente** lleva a su derecha dos botones **▲/▼**
  (`Today::moveStop(RouteStop, 'up'|'down')`) para subirla/bajarla una posición cuando el chofer
  necesita cambiar el orden sobre la marcha. Solo se intercambian dos pendientes contiguas; las
  cerradas conservan su hueco (mismo criterio que `RouteOptimizer` / `Board::reindexColumn`).
  Visibles si `operable() && ! finished && pendingCount > 1`. No hay drag&drop en el chofer (móvil).
  El salto de las tarjetas se **anima con FLIP** (`Livewire.hook('commit', …)` en `app.js`: guarda
  la posición de cada fila `[data-stop-row]` antes del commit y anima `translateY` de la vieja a la
  nueva tras la respuesta; respeta `prefers-reduced-motion`). La fila lleva `wire:key="stop-row-…"`
  para que el morph mueva el nodo en vez de recrearlo.
  - **Modo "Gestionar" (2026-09-24, rediseñado el mismo día tras feedback visual del usuario)**:
    `Today::$reorderLocked` (bool, `true` por defecto, sin persistir — cada carga de página vuelve
    a bloqueado) + `toggleReorderLock()`. Petición explícita del usuario: evitar mover o borrar una
    parada sin querer al tocar la pantalla del móvil. Mientras está bloqueado, ni los botones ▲/▼
    ni el de "Quitar parada" (ver el punto siguiente) se pintan — los tres controles por fila
    quedan ocultos hasta pulsar el botón **"Gestionar"** (pasa a decir **"Listo"** mientras está
    desbloqueado). `$canReorder`/`$canRemove` en el Blade añaden `&& ! $reorderLocked`; `moveStop()`
    lo comprueba también en servidor (`abort_if`) — defensa en profundidad, no solo ocultar el
    botón (`confirmRemoveStop()`/`removeStop()` no lo necesitan porque ya piden confirmación en un
    modal aparte, ver abajo). Botón de candado junto a "Organización automática" (antes "Organizar
    mi ruta" — renombrado el mismo día).
    - **Primer intento con los 3 botones en fila con icono en línea con el texto, descartado**: con
      etiquetas de dos palabras ("Orden bloqueado"…) el texto envolvía a una segunda línea mientras
      el icono se quedaba arriba, descuadrado — el usuario lo señaló explícitamente como mala UX.
      Los tres botones de la barra de acciones (`Ver recorrido`/`Organización automática`/
      `Gestionar`) pasan a **icono encima, etiqueta debajo** (`flex flex-col items-center`, no
      `inline-flex items-center` en línea) — con `items-stretch` implícito del contenedor flex, los
      tres quedan de la misma altura aunque una etiqueta ocupe dos líneas y otra una.
- **Quitar una parada equivocada (2026-09-24, con modal de confirmación propio)**:
  `Today::confirmRemoveStop(RouteStop)` (abre `<x-modal name="remove-stop">`, guarda
  `$removingStopId`/`$removingStopName`) → el modal pregunta **"¿Quitar esta parada?"** con
  Sí/No → `Today::removeStop(RouteStop)` la borra de verdad. **No usa `wire:confirm`** (el diálogo
  nativo del navegador) a propósito — el usuario lo pidió como un modal propio de la app, igual que
  el resto de confirmaciones del sistema de diseño. **Bug real corregido**: si el chofer añadía una
  parada por error (p. ej. con "Añadir cliente"), no tenía ninguna forma de deshacerlo antes de
  pulsar "Empezar jornada" — no hay borrado en el chofer, y reprogramar solo se dispara al CERRAR
  una parada (`StopActionForm`), que exige `guardStarted()` (jornada en curso). `removeStop()` usa
  el mismo guard que `moveStop()`/`addClientStop()` (`authorizeRoute()`, que solo exige
  `operable()` — **no** `guardStarted()` — así que funciona antes y después de empezar la jornada)
  + comprobación manual de propiedad (`$stop->route_id === $this->route->id`, sin policy nueva,
  mismo patrón exacto que `moveStop()` en vez del `RouteStopPolicy::complete` que usan
  `openStop`/`captureStopCoordinates`). Alcance: **cualquier parada `Pending` de su propia ruta**,
  no solo las que él añadió — `route_stops` no tiene ninguna columna que distinga el origen de la
  parada, y no se ha creado una para esto. Considerado seguro porque: (1) el botón solo aparece en
  modo "Gestionar" (ver arriba, no es un toque accidental), (2) siempre pide confirmación en el
  modal, (3) siempre avisa a oficina al momento (**6º tipo** de notificación chofer→oficina,
  `RouteChangeNotifier::stopRemoved()` → `ChoferRouteChanged` kind `stop_removed`, título "Error de
  planificación en una ruta" — texto pedido explícitamente por el usuario, mismo patrón de
  dispatch explícito que los otros 5, nunca observer), (4) nunca toca paradas ya cerradas
  (historial intocable). Botón de papelera (color `rose`, semántico de peligro) en la misma columna
  de acciones que ▲/▼ y el enlace de navegación.
- **Añadir cliente sobre la marcha** (un cliente llama al chofer): botón "Añadir cliente (ha llamado)"
  **al final de la lista de paradas** (visible si `operable()`, es decir hoy o ruta `InProgress`) →
  modal `add-stop` con buscador (`clientMatches`, `Client::scopeSearch`, mín. 2 caracteres) →
  `addClientStop(Client)` crea una `RouteStop` `Pending` al final de la ruta con los datos del cliente
  (mismo copiado que `Clients\Show::planDelivery`, incl. `service_kind`). Autorización:
  `authorizeRoute()` (= `RouteDayPolicy::operate` + `operable()`); no hace falta permiso nuevo, el
  chofer ya tiene `routes.view.own`.
  - **Si la jornada ya está terminada** (`finished`), añadir un cliente **la reabre**: `route`
    vuelve a `InProgress`, se limpian `completed_at` / `liter_meter_end` / `liter_discrepancy_note` y
    `trucks.liter_meter` se revierte a `liter_meter_start`, para que el chofer haga el reparto extra
    y vuelva a cerrar la jornada con la lectura correcta del contador.
- **Contador de litros / cuadre:** es un contador de litros *dispensados* (como el cuentakilómetros,
  pero de litros), **independiente de la cisterna** (el camión puede rellenar o no durante el día).
  `trucks.liter_meter` guarda la última lectura conocida; `routes.liter_meter_start` /
  `liter_meter_end` / `liter_discrepancy_note` la jornada. "Empezar jornada" pide la lectura del
  contador (prefill = `trucks.liter_meter`); el panel del chofer muestra un contador **dinámico**
  ("al empezar X → va por X + repartido"). "Terminar jornada" pide la lectura real; si
  `(fin − inicio) − repartido` supera `config('servalillo.liter_meter_tolerance')` (def. 0), el modal
  avisa ("el contador marca N L más/menos de lo repartido") y **obliga a un motivo del ajuste** antes
  de cerrar; toast `warning` al finalizar con ajuste. Al terminar, `trucks.liter_meter` = lectura de
  fin. Helpers en `RouteDay`: `deliveredLiters()`, `literMeterExpected()`, `literDiscrepancy()`. El
  tablero Kanban del admin marca la columna con aviso ámbar si hay `liter_discrepancy_note`.
  - **Entregas que no pasan por el contador (2026-09-25)**: `route_stops.counted_in_meter`
    (boolean, `true` por defecto). No toda entrega sale de la cisterna que mide el contador del
    camión (p. ej. un repostaje aparte) — al completar una parada, un botón-toggle junto a
    "Litros entregados" ("Pasa por el contador" / "No pasa por el contador", por defecto
    marcado) deja guardar esta excepción por parada (`StopActionForm`). **`deliveredLiters()`
    sigue sumando TODAS las paradas completadas** (es el dato de "cuánto se ha repartido", que
    no cambia — se sigue usando tal cual en `history.blade.php` y en el "Repartido" del chofer);
    se añadió **`RouteDay::meteredLiters()`**, que solo suma las que tienen
    `counted_in_meter = true`, y es la que ahora usan `literMeterExpected()`/`literDiscrepancy()`
    y el cuadre de `Chofer\Today::endDay()`/`endMeterDiscrepancy()` — así una entrega marcada
    aparte no genera un descuadre falso al cerrar la jornada. `FleetStatsService`/`Clients\Show`/
    `DeliveryNoteService` siguen sumando `delivered_quantity` tal cual, sin este filtro, a
    propósito: no son cálculos de cuadre de contador, son estadísticas/historial de reparto.
  - **Estado del contador en el tablero del administrador (2026-09-25)**: la cabecera de cada
    columna del Kanban (`routes/board.blade.php`) muestra ahora, si la jornada ya empezó
    (`liter_meter_start` no nulo), una línea con el estado del contador — "Contador: :inicio L ·
    esperado :esperado L" mientras está en curso, o "Contador: :inicio → :fin L" ya cerrada —
    usando `literMeterExpected()` (por tanto ya excluye lo no contabilizado). El aviso ámbar de
    `liter_discrepancy_note` (ya existente) se queda debajo, solo si hubo que ajustar al cerrar.
- **`<x-ui.digit-wheel unit="…">`** = selector numérico tipo "ruleta" (una columna scroll-snap por
  dígito) para las lecturas del contador de litros. `wire:model` **diferido** (no `.live`): el valor
  sincroniza al enviar el form, así que el aviso de descuadre del modal de terminar jornada aparece
  tras el primer "Terminar". Se integra con Livewire vía `x-modelable="value"` + `wire:model`; la
  lógica de scroll está en `Alpine.data('digitWheel')` (`app.js`). Gotchas:
  - El alto de item (`44px`) está **duplicado** en `app.css` (`.digit-wheel__item` → `h-11`) y en
    `app.js` (`WHEEL_ITEM_H`). Si cambias uno, cambia el otro.
  - Cuando el modal se abre (`open-modal`), el componente re-lee el valor con `$wire.get()` y
    recoloca las ruletas (`resync()`), porque al estar `display:none` no se puede fijar `scrollTop`.
  - El detalle del evento `open-modal` de Livewire llega **envuelto en array** — compáralo con `==`
    (como hace `<x-modal>`), nunca con `===`.

