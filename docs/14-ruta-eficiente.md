# Ruta eficiente y Ver recorrido (Bloque 13)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Ruta eficiente (Bloque 13)
- Botón **"Ruta eficiente"** que reordena automáticamente las paradas **pendientes** de una ruta
  para acortar el recorrido. Dos entradas: cabecera de cada columna de ruta del tablero (`/rutas`,
  `Board::startOptimize`/`runOptimize`) y bajo la lista de paradas del chofer (`/chofer/ruta`,
  `Today::startOptimize`/`runOptimize`, "Organizar mi ruta").
- **Flujo en dos pasos**: `startOptimize` abre `<x-route-optimize-modal>` (compartido) que pregunta
  **"¿Desde dónde sale el camión?"** → **"Desde la base"** (`config('servalillo.base')`, editable por
  `BASE_LATITUDE`/`BASE_LONGITUDE`) o **"Desde un cliente"** (lista de paradas **pendientes con
  ubicación** de la ruta, `#[Computed] optimizingStops`). El botón elegido llama a
  `runOptimize('base'|<stopId>)`, que resuelve el `$origin` `[lat, lon]` y llama a `optimize()`.
  Los dos componentes exponen `runOptimize(string $from)` con la misma firma para que el modal sirva
  para ambos.
- **Chofer — "Ir a la base"**: enlace simple a Google Maps (`Today::baseNavUrl()` →
  `GoogleMaps::pointUrl(config('servalillo.base.*'))`), pequeño y discreto a propósito (no es de
  uso frecuente) en la cabecera de la tarjeta de la ruta, junto al badge de estado — solo
  visible si hay ruta hoy. **`Today::optimizeFromBase()` ("Ir a la base a repostar", que
  reordenaba las pendientes con un solo toque) se quitó del todo (2026-09-14)** — redundante con
  "Organizar mi ruta" → "¿Desde dónde sale el camión?" → "Desde la base" (mismo modal de arriba),
  que sigue intacto para quien quiera esa reordenación explícita.
- **`App\Services\RouteOptimizer` es el único punto.** `optimize(RouteDay, ?array $origin = null): array`
  + `toast(array): array` + `baseOrigin(): array`.
  - Motor: **OSRM `/table`** (`?annotations=distance`) → matriz N×N de distancias reales por carretera.
    Sobre esa matriz, **vecino más cercano + 2-opt** (camino abierto). Config `servalillo.routing`
    (`OSRM_URL` autoalojable, demo público sin API key; `timeout`/`connect_timeout`). OSRM quiere
    **`lon,lat`**. **OJO**: NO usar `/trip` con `roundtrip=true` — optimiza un circuito cerrado y con
    la pierna de vuelta descartada puede dejar el camino abierto *peor*.
  - **Punto de partida (`$origin`)**: lo elige quien llama (base / parada del modal / **posición real
    del camión**) → se antepone como índice 0 fijo y la primera pendiente pasa a ser la más cercana.
    Si **no se pasa `$origin`** (llamada directa / tests), se toma la **última parada cerrada** con
    coordenadas; si tampoco hay, la optimización es **libre** (NN desde cada inicio, el camino más corto).
  - **"Ubicación actual del camión"** (Bloque 11): `RouteOptimizer::latestVehiclePosition(RouteDay, $maxAgeMin=60)`
    devuelve `[lat,lon]` de la última `GpsPosition` de la ruta (match por `route_id`, o `driver_id` +
    `route_date`) si es reciente; `vehiclePositionAge()` da el "hace X min". El modal
    `<x-route-optimize-modal :vehicle-age>` muestra esa opción **destacada** cuando hay señal reciente
    (`Board::optimizingVehicleAge` / `Today::optimizingVehicleAge` computed); `runOptimize('vehicle')`.
  - **Fallback obligatorio** (`App\Support\Haversine`, mismo algoritmo con distancia en línea recta):
    cualquier fallo de OSRM (red, timeout, `code != Ok`, par no ruteable, `enabled=false`) → local.
    El botón **siempre** da resultado (`method` = `osrm` | `local` | `none`).
  - **Nunca empeora**: se compara el orden propuesto con el actual (misma métrica, con `$origin`
    delante si lo hay); si el actual ya es igual o mejor, no se toca (`moved: false`, toast "ya
    estaba optimizada").
  - Solo reordena `Pending`; las cerradas conservan su hueco relativo al flujo de pendientes (su
    `position` puede renumerarse al compactar). Las pendientes **sin `lat/lon`** se anexan al final
    en su orden. `latitude/longitude` son `decimal:7` → **`(float)` antes de operar**.
  - Persiste `position` en `DB::transaction`, solo filas que cambian. Coste: ~N filas de `audits`
    por clic, igual que `reorderStops` — aceptado.
  - **Gotcha resuelto**: NO usar una arrow-fn `fn () => array_shift($queue)` dentro de `map()` para
    drenar una cola — las arrow functions capturan **por valor** y `array_shift` no persiste entre
    iteraciones. Usar un `foreach` normal.
- Permiso nuevo **`routes.optimize.own`** (chofer + admin + mantenimiento). `RouteDayPolicy::optimizeOwn`
  (chofer, con propiedad del día) y `RouteDayPolicy::reorderStops` (oficina — cableado desde
  `Board::startOptimize` con `$this->authorize('reorderStops', $route)`).
- Primer uso del **`Http` facade** de la app. En tests: `phpunit.xml` fija `ROUTING_OSRM_ENABLED=false`
  (heurística local determinista, sin red); los tests de OSRM hacen `config()->set(...)` + `Http::fake()`.
- **"Ver recorrido"** (mismo bloque): botón que abre un mapa **Leaflet** (`npm i leaflet`, mosaicos
  de OpenStreetMap, sin API key) con las paradas numeradas y el **trazado real por carretera**.
  - `App\Services\RouteGeometry`: `for(array $points): ?array` pide a OSRM `/route/v1/driving/{lon,lat…}
    ?overview=full&geometries=geojson` y devuelve `{ line: [[lat,lon]…], distance_m, duration_s }` o
    null (el mapa cae a línea recta entre paradas). `payloadFor(RouteDay): array` arma
    `{ stops:[{n,name,lat,lng,status}], meta, skipped, vehicle }` (numeración por posición real,
    aparta las paradas sin coordenadas).
  - **`vehicle`** (Bloque 11): última `GpsPosition` **del chofer** (`where('driver_id', ...)`, sin
    filtrar por fecha — 2026-09-10: antes exigía `route_id` de ese `RouteDay` o `recorded_at` de
    ese `route_date` concreto, así que un día sin actividad todavía [futuro] o ya pasado no
    mostraba camión; ahora es siempre "dónde está el dispositivo ahora mismo", da igual qué día se
    esté mirando en el tablero) + sin límite de antigüedad (se muestra "hace X") + `approach` =
    trazado por carretera desde el camión hasta la **primera parada pendiente** (`next_stop`) de
    ese día. `routeMap` lo pinta como marcador 🚚 ámbar con "ping" + línea ámbar discontinua ("cómo
    llegar a la 1ª parada", con km/min en `approachNote`). `.route-map-vehicle` en `app.css`.
  - `Board::showRouteMap(int $routeId)` (`authorize('view')`) y `Today::showRouteMap()`
    (`authorize('operate')` — la ruta propia, cualquier día) emiten el evento **`open-route-map`**
    con ese payload.
  - `Alpine.data('routeMap')` (`app.js`) escucha `open-route-map`, abre `<x-modal name="route-map">`
    y, cuando el contenedor ya tiene tamaño (estaba `display:none`), monta el mapa +
    `invalidateSize()`. Componente Blade compartido `<x-route-map-modal>` (chofer + tablero).
  - **Gotchas Leaflet**: (1) el preflight de Tailwind (`img{max-width:100%}`) descoloca los
    mosaicos → override `.leaflet-container img { max-width: none }` en `app.css`; (2) el icono de
    marcador por defecto se rompe con Vite → se usa `L.divIcon` con HTML (`.route-map-pin`
    numerada); (3) el `<div>` del mapa lleva `wire:ignore`.
  - **⚠️ Bug real de producción (2026-09-11): `tile.openstreetmap.org` empezó a devolver 403
    "Access blocked — App is not following the tile usage policy" en los dos mapas Leaflet de la
    app** (`routeMap` = "Ver recorrido"; `deviceMap` = "Localizar" en Mantenimiento → Dispositivos).
    Los servidores de mosaicos de OSM son de voluntarios y su política exige registro/`User-Agent`
    identificable y limita el tráfico por IP — un hotlink directo desde el navegador del usuario
    (sin backend intermedio que añada esas cabeceras) no lo cumple, y en algún momento empezaron a
    bloquear esta app en concreto.
    - **Primer intento (CARTO Voyager) NO sirvió**: se supuso "gratis sin API key para este
      volumen", pero en producción los mosaicos volvían con el mapa en blanco y un texto
      superpuesto repetido en diagonal — "API KEY REQUIRED · carto.com/basemaps/apikey" — CARTO
      exige cuenta/key incluso a bajo volumen ahora. **Lección: no dar por buena una URL de
      mosaicos sin descargar un tile real y mirarlo** (un `curl` que da 200 no basta: la respuesta
      puede ser igualmente un 200 con una imagen-aviso en vez del mapa).
    - **Fix real**: los dos `L.tileLayer(...)` de `resources/js/app.js` pasan a los tiles REST
      públicos de **ArcGIS Online** (`server.arcgisonline.com/.../World_Street_Map/MapServer/tile/
      {z}/{y}/{x}` — **ojo, orden z/y/x, al revés que OSM/CARTO**), verificado descargando un tile
      real sobre Alicante antes de fijarlo (sin key, sin marca de agua, ~34 KB con calles/etiquetas
      de verdad, frente a los ~1,7 KB de las respuestas-aviso de CARTO). Si esto también acabara
      limitado algún día, la alternativa es un proveedor con API key (Stadia Maps / MapTiler) o un
      proxy propio de mosaicos con caché.

