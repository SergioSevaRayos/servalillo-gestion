# Tiempo de permanencia en parada (Bloque 15)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Tiempo de permanencia en parada (Bloque 15, 2026-09-11)
- **Cuánto tiempo está el camión parado en cada cliente**, a partir del GPS y una **geocerca**
  (radio configurable, 100 m por defecto). Cálculo por **"replay"**, NO máquina de estados en la
  ingesta: `App\Services\StopDwellService::recomputeForRouteDay(RouteDay)` reprocesa el track
  completo del día y reconstruye la tabla `stop_visits` (borra + reinserta por día → idempotente,
  aguanta lotes de GPS desordenados / de recuperación). No toca `GpsIngestService`.
- **`stop_visits`** (`route_stop_id` cascade, `route_id` → `route_days` nullOnDelete, `entered_at`,
  `left_at` nullable, `seconds` unsignedInteger nullable). `left_at`/`seconds` null = **visita
  abierta** ("en parada ahora"). Modelo `App\Models\StopVisit`, **no auditado** (dato derivado,
  mismo criterio que `gps_positions`). `RouteStop::visits()` + accesores `onSiteSeconds()` (suma;
  una visita abierta cuenta lo transcurrido hasta ahora), `firstArrivalAt()`, `lastDepartureAt()`,
  `isOnSiteNow()`. `RouteDay::stopVisits()` (hasMany por `route_id`) para el total por día.
- **Reglas** en `config('servalillo.dwell')` (`DWELL_*` env): `radius_meters` **150** (subido de
  100 tras pruebas de campo — algunas paradas quedaban justo en el límite), `min_seconds` 120 (por
  debajo no cuenta como parada — "pasaba por la calle"), `merge_gap_seconds` **600** (10 min —
  subido de 180 tras pruebas de campo, ver el apartado de cobertura justo abajo),
  `accuracy_reject_meters` 150 (se ignoran fixes con mala precisión; `accuracy_m` null se acepta),
  `exclude_base_radius_meters` 150 (se ignoran fixes junto a la nave — **editable desde
  `/mantenimiento/ajustes`**, ver "Ajustes generales" más abajo: es el radio en el que el sistema
  considera "está en la base", así una parada ahí nunca se cuenta como no programada aunque el
  camión esté un buen rato), `clamp_to_shift` true (se ignora lo de fuera del horario de jornada
  ±30 min). Cada posición se atribuye a la parada **más cercana dentro del radio** (dos geocercas
  solapadas no cuentan doble).
- **"Sin cobertura" durante una parada — verificado y documentado (2026-09-11).** El usuario pidió
  comprobar que una pérdida de señal del chofer durante una parada no rompe el conteo. Hay dos
  cosas distintas bajo ese nombre:
  - **Sin cobertura de RED (datos móviles)**: la APK captura GPS con `Geolocator.getCurrentPosition()`
    (API del sistema, satélite/GNSS) **sin ninguna llamada de red** — cada tick (~45 s) se guarda en
    una cola SQLite local pase lo que pase con la conexión, y `sync_service.dart` la manda entera,
    en orden, en cuanto vuelve la cobertura (borra cada lote solo tras un `2xx` confirmado; nada se
    pierde salvo ~26 días seguidos sin red, tope de la cola en 50 000). Como `StopDwellService`
    funciona por "replay" y solo le importa `recorded_at` (nunca cuándo llegó el dato), esto **ya
    estaba cubierto por diseño** — no genera ningún hueco real.
  - **Sin señal GPS** (nave cubierta, interior, timeout del GPS del móvil): aquí sí no hay fix que
    guardar, hueco real en `recorded_at`. Esto lo gobierna `merge_gap_seconds`: por debajo del
    umbral, `splitIntervals()` **puentea el hueco entero** (se asume que el camión siguió ahí
    parado); por encima, corta en una visita nueva. **Bug real encontrado y corregido**: antes
    había dos umbrales (`merge_gap_seconds` 180 y `max_gap_seconds` 600) pero el primero SIEMPRE
    cortaba antes de que el segundo pudiera aplicar el tope — código muerto, y cualquier hueco de
    más de 3 min ya rompía la parada en trozos (a menudo perdiéndola entera si cada trozo quedaba
    por debajo de `min_seconds`). Se unificó en **un solo umbral** (`merge_gap_seconds`, ahora 600 s):
    como cada hueco interno queda por construcción `≤ merge_gap_seconds`, la duración de una visita
    puenteada es simplemente `última − primera` (`Carbon::diffInSeconds()`), sin necesidad de topar
    cada hueco aparte.
  - **Gotcha de Carbon 3 (Laravel 13) que costó depurar**: `Carbon::diffInSeconds()` **ya NO es
    absoluto por defecto** (cambio de comportamiento respecto a Carbon 2) — `$a->diffInSeconds($b)`
    devuelve `$b − $a` (positivo si `$b` es posterior a `$a`, **negativo** si es anterior). Al
    simplificar `splitIntervals()` se escribió `$previous->diffInSeconds($start)` con los
    argumentos al revés (`$start` es el más antiguo) → duración **negativa** para CUALQUIER visita
    → siempre `< min_seconds` → **ninguna visita se guardaba nunca**, en todo el fichero (se detectó
    porque toda la suite de `StopDwellServiceTest` se puso en rojo salvo los casos que esperaban 0
    resultados). Mismo fallo en `transitLegs()`/`buildLeg()`. Arreglado invirtiendo el orden:
    `$start->diffInSeconds($previous)` / `$departedAt->diffInSeconds($arrivedAt)`. Si se toca esta
    zona otra vez: **comprobar siempre el signo con un test real**, no fiarse de la intuición de
    Carbon 2.
- **Cuándo se recalcula**: (1) comando **`paradas:calcular-permanencia {fecha?}`** — sin argumento
  ayer + hoy (todas las rutas, no solo operativas: de madrugada los días de ayer ya están
  "Completada" y son justo los que hay que cerrar); scheduler `dailyAt('03:30')` (antes de
  `gps:purgar`). (2) **Recálculo perezoso al ver**: `Board::render()` / `Chofer\Today::route()` /
  `History::viewDay()` recalculan los días visibles recientes (`route_date >= hoy −
  `recompute_max_age_days` (2)`) cuyo sello `route_days.dwell_recalculated_at` esté caducado
  (`recompute_every_seconds`, 90 s). Doble barrera contra estampida de polls: el sello + un
  `Cache::lock("dwell:routeday:{id}")` **no bloqueante** dentro del servicio (si está tomado, se
  sale sin tocar nada; el siguiente poll trae datos frescos). Latencia del "en parada ahora": ~2-3
  min (ping 45 s + poll + throttle). `dwell_recalculated_at` está en `RouteDay::$auditExclude`.
  Tras cambiar `radius_meters`/`merge_gap_seconds` en producción, hace falta relanzar el comando a
  mano una vez para recalcular los días recientes con las reglas nuevas (el sello no caduca solo).
- **UI**: `<x-stop-dwell :stop variant="badge|line" />` (componente compartido, usa
  `App\Support\Duration::humanShort()`). `badge` (`⏱ 14 min`, o `● En parada 6 min` ámbar si
  abierta) en las tarjetas del tablero y del chofer; `line` (`Llegada 10:32 · Salida 10:49 · 17 min
  en parada`) en el modal `stop-action` del chofer y en `day-detail` del Historial. Resúmenes:
  columna "En paradas" por día en el Historial (`withSum('stopVisits as on_site_seconds', 'seconds')`)
  y stat "Media en parada" en la ficha del cliente (`Client::pastStops()` ya trae `visits`).
- **Velocidad actual del camión (2026-09-11)**: `RouteGeometry::vehicleFor()` ya incluía la última
  `GpsPosition` del chofer para "Ver recorrido" (`lat, lng, recorded_at, age, accuracy_m, approach,
  next_stop`); ahora suma **`speed_kmh`** (redondeado desde `gps_positions.speed_mps × 3.6`, `null`
  si no hay dato — mismo cálculo que `Maintenance\Devices::locate()` para su mapa de "Localizar").
  Se pinta como una línea propia (`vehicleNote`, "🚚 Camión: hace 2 min · 42 km/h") bajo el mapa de
  `<x-route-map-modal>`, en `Alpine.data('routeMap')` (`resources/js/app.js`) — visible en el
  tablero, la web del chofer y el Historial, todos comparten el mismo componente.
- **Tiempo y velocidad entre dos paradas (2026-09-11)**: `StopDwellService::transitLegs(RouteDay):
  array` — **no se persiste**, se calcula al vuelo a partir de `stop_visits` ya construidas
  (`RouteStop::firstArrivalAt()`/`lastDepartureAt()`) + una consulta puntual de
  `gps_positions.speed_mps` en la ventana `[salida de la anterior, llegada a la siguiente]`,
  convertida a km/h. Recorre las paradas en orden de `position`; si una intermedia no tiene visita
  (sin geocerca), se salta y el tramo se calcula hasta la siguiente que sí la tenga.
  `App\Livewire\Routes\History::transitLegs()` (`#[Computed]`, indexado por el id de la parada de
  **llegada**) lo pinta en `history.blade.php` como una fila "🚚 En ruta 18 min · 42 km/h de media
  (máx. 61)" justo antes de la parada a la que llega ese tramo.
  - **Bug real de producción corregido (2026-09-11): la media salía absurdamente baja.** Un tramo
    real dio "59 min · 16 km/h de media (máx. 139)" — inspeccionado el GPS crudo: el camión estuvo
    **~40 de esos 59 min totalmente parado** (velocidad 0, sin ninguna parada registrada ahí — una
    espera o un descanso) y solo ~15 min circulando de verdad; la MEDIA se calculaba sobre la
    ventana entera, así que el tiempo parado la arrastraba muy por debajo de la velocidad real de
    conducción. El máximo (139 km/h) **sí era un dato real** (tres posiciones GPS consecutivas a
    ritmo constante, no un pico suelto) — no se toca. **Fix**: la media (`avg_speed_kmh`) ahora
    solo cuenta las muestras con `speed_mps >= config('servalillo.dwell.moving_speed_min_mps')`
    (1,0 m/s por defecto, `DWELL_MOVING_SPEED_MIN_MPS`) — "velocidad mientras circulaba", no
    "velocidad media incluyendo los ratos parado". El máximo (`max_speed_kmh`) se queda sin
    filtrar (un pico ya descarta por sí solo cualquier lectura a 0, no hace falta filtrarlo).
- **Paradas no programadas (2026-09-13)**: cuando el camión está parado ≥5 min (configurable) en
  un punto que NO es ni una parada de la ruta ni la base — repostar por libre, un desvío, una
  avería — queda anotado. `App\Models\UnplannedStop` (tabla `unplanned_stops`, mismo criterio de
  dato derivado que `stop_visits`: `StopDwellService::run()` la reconstruye entera por día, borra +
  reinserta). `StopDwellService::buildUnplannedStops()` agrupa los fixes que sobran tras descartar
  los de cualquier parada de la ruta (la exclusión de la base ya la hace `filterPositions()`) y
  los que traen velocidad real de circulación (`speed_mps >= dwell.moving_speed_min_mps`, mismo
  umbral que `transitLegs()`): a diferencia de `buildVisits()` (geocerca fija conocida), aquí se
  agrupan fixes consecutivos cercanos al ANCLA del grupo (`unplanned_stop_radius_meters`, 100 m
  por defecto) — no hay un punto fijo de referencia previo, así que el primer fix del grupo hace
  de ancla. Mismo puente de huecos de señal GPS que las visitas normales (`merge_gap_seconds`).
  **Bug real de producción corregido (2026-09-14): circular despacio entre dos paradas (tráfico,
  calles estrechas, muchos giros) se marcaba como parada no programada.** La primera versión
  comparaba cada fix con el ANTERIOR del grupo, no con el ancla: con el camión avanzando poco a
  poco, dos fixes consecutivos (~45 s aparte) pueden quedar a menos del radio de agrupación el
  uno del otro sin que el camión dejara de moverse ni un instante — la cadena de saltos cortos
  nunca superaba el radio fix a fix, aunque el recorrido acumulado sí. Doble fix: (1) la
  distancia se mide siempre contra el ancla (primer fix del grupo), que si el camión sigue
  avanzando acaba quedando fuera del radio tarde o temprano; (2) un fix con velocidad real de
  circulación queda excluido de entrada, sin ni siquiera entrar en el cálculo de distancia.
  **Visible SOLO para administración/mantenimiento**: `RouteGeometry::payloadFor(RouteDay,
  $includeUnplannedStops = false)` las omite por defecto; solo `Routes\Board::showRouteMap()` y
  `Routes\History::showDayMap()` piden `true` — `Chofer\Today::showRouteMap()` nunca lo pasa, así
  que el chofer no las ve nunca, ni en "Ver recorrido" ni en ningún otro sitio (no tiene "Ver
  detalle"). En el mapa se pintan con el icono estándar de aviso (⚠️) + el tiempo parado (p. ej.
  "⚠️ 12 min"), mismo patrón de píldora que las paradas cerradas (`.route-map-pin--pill`); en
  `history.blade.php` ("Ver detalle") salen listadas con hora de entrada/salida, duración y un
  enlace a Google Maps (`App\Support\GoogleMaps::pointUrl()`). El umbral de minutos es configurable
  desde `/mantenimiento/ajustes` (ver más abajo, "Ajustes generales"). **Notifica a
  administración por la campana** al detectarse (`App\Notifications\UnplannedStopDetected`,
  una vez por parada real, no por recálculo — ver el detalle completo en "Notificaciones y
  canal de soporte", Bloque 12).
  **Segundo bug real de producción corregido (2026-09-14): una parada no programada en el mismo
  sitio que una parada de la ruta ya CERRADA (completada/cancelada/fallida) no se detectaba.**
  `buildUnplannedStops()` excluía cualquier fix dentro del radio de CUALQUIER parada de la ruta
  sin mirar la hora — la geocerca de una parada "protegía" ese punto todo el día, aunque el
  camión la hubiera visitado y cerrado horas antes; si volvía a esa zona más tarde por otro
  motivo (repostar, un desvío…), ese tramo quedaba invisible. **Fix**: una parada `Pending` sigue
  protegiendo su geocerca sin límite de hora (todavía puede visitarse en cualquier momento), pero
  una parada CERRADA (`RouteStopStatus::isClosed()`) solo la protege **hasta el momento en que se
  cerró** — aproximado con `updated_at` (única marca de tiempo común a los 3 estados terminales;
  `completed_at` solo existe para `completed`). Pasado ese instante, un fix dentro de su radio
  vuelve a ser candidato a "no programada". **`buildVisits()` no se toca a propósito**: sigue
  atribuyendo cualquier fix cercano a la parada más próxima sin mirar la hora, así que una
  segunda visita tardía a una parada ya cerrada se sigue sumando a sus estadísticas de
  permanencia (dato útil) Y, con este fix, también puede aparecer como no programada — mejor
  visibilizar la anomalía dos veces que ocultarla del todo.
- **Camión "en la base" en el mapa "Ver recorrido" (2026-09-14)**: antes, si el camión estaba
  parado dentro del radio de la base, el mapa igualmente calculaba y pintaba un trazado "cómo
  llegar a la parada 1" (línea ámbar + "Del camión a la parada X: ~Y km") como si estuviera en
  ruta — confuso, parecía que iba de camino cuando en realidad seguía en la nave.
  `RouteGeometry::vehicleFor()` calcula ahora `at_base` (mismo `Haversine::meters()` +
  `config('servalillo.dwell.exclude_base_radius_meters')` que usa `StopDwellService::
  filterPositions()` para excluir esos mismos fixes de las visitas/no programadas — una sola
  fuente de verdad para "está en la base") y, si es `true`, **no calcula `approach`/`next_stop`
  en absoluto** (ni pide nada a OSRM para ese trazado). `Alpine.data('routeMap')` (`app.js`)
  pinta `vehicleNote` como "🚚 Camión: en la base (hace X)" en vez de la nota de velocidad/ruta,
  y omite la línea/nota de aproximación. **Nota**: si en producción el camión aparece "en curso"
  como parada no programada estando realmente aparcado en la nave, la causa más probable es que
  el punto/radio configurados en `/mantenimiento/ajustes` no cubran del todo la zona real de
  aparcamiento — ese ajuste (sin desplegar) es la vía normal para calibrarlo, no un bug de este
  código: `filterPositions()` ya excluye de raíz cualquier fix dentro del radio configurado, así
  que un aviso real ahí significa que ese fix cayó FUERA del radio actual.
- **Fuera de alcance** (posible fase posterior): detección de entrada/salida en tiempo real en la
  ingesta; interpolación del cruce exacto del radio; media por chofer en `FleetStatsService`;
  aviso "parada completada pero el camión nunca entró en su radio"; orientar el marcador del
  camión con `heading_deg` (se captura y guarda, pero no se usa en ningún
  sitio todavía).
- Tests: `StopDwellServiceTest` (34 casos: conteo, drive-by, split, geocercas solapadas, base,
  precisión, re-entrada, visita abierta, idempotencia, lote desordenado, sin coords,
  clamp_to_shift, puente de hueco de señal GPS, corte por encima del umbral, límite exacto, lote de
  recuperación tras sin cobertura, y desde 2026-09-13 la detección de paradas no programadas — con
  umbral, radio, exclusión por parada/base, varias distintas, abierta e idempotencia, circulación
  despacio entre paradas [bug del 2026-09-14], exclusión por velocidad real, no programada tras
  cerrarse una parada [2º bug del 2026-09-14], protección sin límite de hora de una parada
  todavía pendiente, y desde 2026-09-14 la notificación a administración — detecta y avisa, no
  repite tras recalcular, dos paradas distintas avisan dos veces, una visita normal no notifica,
  un chofer no la recibe), `TransitLegsTest`,
  `RecomputeStopDwellCommandTest`, `StopDwellOnViewTest`,
  `RouteStopDwellAccessorsTest`, y las nuevas aserciones de `RouteGeometryTest` (incl. "camión en
  la base")/`RoutesBoardTest`/
  `RouteHistoryTest`/`ChoferTodayTest` sobre la visibilidad de `unplanned_stops`. Helpers en
  `tests/Pest.php`: `metersOffset()`, `gpsTrack()`.

