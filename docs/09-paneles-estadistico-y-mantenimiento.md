# Panel estadístico y Panel de Mantenimiento (Bloques 5-6)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Panel estadístico (Bloque 5)
- **`/dashboard` es ahora un componente Livewire** (`App\Livewire\Dashboard\Index`), ya no un
  `Route::view`. Sigue siendo la landing de gestión (redirección post-login de admin/mantenimiento) pero
  es el panel de KPIs completo. Ruta con `->middleware('permission:stats.view')` **y** `abort_unless`
  en `mount()` (los tests via `Livewire::test` se saltan el middleware de ruta).
- **`App\Services\FleetStatsService`** es el único sitio donde se agregan las métricas. `report(Carbon
  $from, Carbon $to)` devuelve un array con `kpis`, `operations` (serie diaria + estados de ruta),
  `volume` (planificado vs entregado + por tipo de reparto), `by_driver`, `by_truck`. Todo con
  **agregación en BD** (Query Builder + `filter (where ...)` de Postgres), no trayendo filas. Como
  `route_days`/`route_stops` usan SoftDeletes y el Query Builder no las scopea, cada consulta añade
  `whereNull('...deleted_at')` a mano — si tocas el service, no lo olvides.
- Toda la operativa se cuenta por la **fecha del día de ruta** (`route_days.route_date`), no por
  `completed_at`, para que "el periodo" sea coherente entre paradas completadas y falladas.
- Rango de fechas: `#[Url] $range` en `{7d,30d,90d,year}` (constante `Index::RANGES`), def. `30d`.
- **Gráficos = Chart.js** (`npm install --save-exact chart.js`, `import Chart from 'chart.js/auto'` en
  `app.js`). Componente Alpine `window.statsCharts(initial)`:
  - Los `<canvas>` van dentro de un bloque **`wire:ignore`** para que el morph de Livewire no los
    toque al cambiar de rango. En su lugar, `setRange()` hace `$this->dispatch('stats-updated',
    charts: $this->chartData())` y el listener de Alpine solo hace `chart.update()`.
  - **Las instancias de Chart NUNCA se guardan en `this`** del componente Alpine — Alpine haría un
    Proxy reactivo de todo el árbol interno de Chart.js (con referencias circulares) → *"Maximum call
    stack size exceeded"*. Se quedan en una variable local del closure de `init()`; solo se expone
    `_cleanup` (una función) para el `destroy()`.
  - Colores fijos que funcionan en claro y oscuro (slate-400 semitransparente para ejes) → no hace
    falta reconstruir los charts al cambiar de tema.
- El `DatabaseSeeder` genera ~90 días de rutas históricas (`seedHistory()`, con guarda de idempotencia)
  para que el panel tenga datos. `migrate:fresh --seed` deja **4 rutas permanentes** (una por camión;
  C-04 sale con `service_kind = viaje`, para el demo del filtro Reparto/Viajes) y sus **~250
  `RouteDay`** con ~1200 paradas.

### Panel de Mantenimiento (Bloque 6)
- **Exclusivo del rol `mantenimiento`** (no `administrador`). Grupo `maintenance.*` en `routes/web.php`
  con `role:mantenimiento`; enlace "Mantenimiento" en el nav solo si `auth()->user()->isMaintenance()`.
- **`/home` redirige al rol `mantenimiento` a `/mantenimiento`** (no a `/dashboard`); ese usuario tiene
  su propio **panel de estadísticas** (`App\Livewire\Maintenance\Overview`, pestaña **"Resumen"**,
  Bloque 12) — el equivalente de `/dashboard` para el técnico. `App\Services\MaintenanceStatsService`
  (mismo patrón de agregación en BD que `FleetStatsService`; `support_tickets` usa SoftDeletes → se
  excluye `deleted_at`) alimenta: selector de rango (7/30/90 días), KPIs (incidencias abiertas / sin
  responder / resueltas, errores del periodo y de 24 h, cambios auditados, notificaciones sin leer,
  peso del log), 4 gráficos Chart.js (errores/día, incidencias abiertas vs resueltas, auditoría/día,
  incidencias por categoría) vía `Alpine.data('maintenanceCharts')` (bloque `wire:ignore`, evento
  `maint-stats-updated` — gemelo de `statsCharts`), barras "top excepciones" / "top modelos", y
  **avisos accionables** (`MaintenanceStatsService::alerts()`, independientes del rango: incidencias
  sin responder > 2 días, errores en 24 h, log > 5 MB, errores de > 30 días sin purgar). Más tarjetas
  con las últimas incidencias / errores / auditorías / ficheros de log. `/dashboard` (panel de la
  empresa) sigue accesible desde el nav, pero ya no es su landing.
- Sub-vistas con pestañas compartidas (`<x-maintenance.tabs>`) — **"Resumen"** (`maintenance.index`,
  antes un `Route::redirect`), "Auditoría", "Errores del sistema", "Log de la aplicación", "Soporte"
  (Bloque 12):
  - **`/mantenimiento/auditoria`** (`App\Livewire\Maintenance\Audits`) — lista de `OwenIt\Auditing\
    Models\Audit` con filtros (búsqueda por usuario/modelo, tipo de modelo, evento, rango de fechas).
    Modal de detalle con `$audit->getModified()` (campo / antes / después) + url/ip/user-agent.
    La policy de `Audit` se registra a mano en `AppServiceProvider` (`Gate::policy`), no se
    auto-descubre porque el modelo vive en el paquete.
  - **`/mantenimiento/errores`** (`Errors`) — lista de `ErrorLog` (excepciones 5xx que captura
    `App\Support\ErrorLogger`). Filtros + modal con traza (`context['trace']`). Acciones: borrar uno
    (`deleteLog`) y purgar los de > 30 días (`purgeOld`).
  - **`/mantenimiento/log`** (`SystemLog`) — visor de `storage/logs/*.log`. Lee **solo la cola** del
    archivo (`TAIL_BYTES`) para no cargar logs enormes, parte las entradas por la cabecera con fecha,
    filtra por nivel y texto. `safePath()` valida que el nombre sea `*.log` dentro de `storage/logs`
    (sin path traversal) — si tocas esto, mantén esa comprobación.
  - **`/mantenimiento/accesos`** (`App\Livewire\Maintenance\LoginLogs`, 2026-09-10) — quién y cuándo ha
    iniciado sesión: tabla `login_logs` (append-only, `UPDATED_AT = null`, mismo criterio que
    `ErrorLog`), una fila por login correcto (usuario, IP, user-agent, `logged_in_at`). Se registra en
    el único punto de login real, `LoginForm::authenticate()` (Volt `pages.auth.login`), junto al
    `last_login_at` que ya existía. `user_id` es `nullOnDelete` (no cascade) a propósito: el acceso
    histórico sobrevive aunque la cuenta se borre de verdad más adelante — la vista muestra "Cuenta
    eliminada" cuando `$login->user` es `null`. Filtros: nombre/email + rango de fechas. Reutiliza el
    permiso `system_logs.view` (mismo que Errores/Log) — no hizo falta un permiso nuevo.
    **Ampliado (2026-09-10, feedback del usuario tras ver la Auditoría con "Modificado Usuario" sin
    cambios):** (1) el login **también** crea una fila en `audits` (`event = 'login'`, `auditable_type
    = User::class`, `old/new_values` vacíos) — aparece en `/mantenimiento/auditoria` con su propia
    etiqueta "Inicio de sesión" (`$eventMeta['login']` en `audits.blade.php`, con fallback genérico si
    algún día se te olvida añadir un evento nuevo ahí). (2) Selección múltiple + **"Eliminar
    seleccionados"** (checkboxes solo de la página actual — `toggleSelectAll()` no toca otras páginas)
    y **purga manual/automática** por retención (`config('servalillo.login_log_retention_days')`,
    def. 90; comando `accesos:purgar {--dias=}`, scheduler diario 04:10, detrás de `gps:purgar`).
    (3) **"Conectados ahora"**: `users.last_seen_at` (nullable, excluido de auditoría en
    `$auditExclude` — si no, cada visita generaría ruido), actualizado por `EnsureUserIsActive`
    (middleware global `web`) con `saveQuietly()` y limitado a **una escritura por minuto por
    usuario** (evita machacar la BD en cada petición/poll). Un usuario es "conectado" si su
    `last_seen_at` cae dentro de `config('servalillo.online_window_minutes')` (def. 3 min) —
    `LoginLogs::onlineUsers()`, franja verde arriba del todo con `wire:poll.30s`.
- El auditing real está **desactivado en consola** (`config/audit.php` → `console => false`), así que
  el seeder inserta filas de `audits` a mano (`seedMaintenanceData()`) imitando eventos web, además
  de unos cuantos `ErrorLog` de ejemplo.
- **`/mantenimiento/ajustes` (`App\Livewire\Maintenance\Settings`, 2026-09-13)** — ajustes generales
  editables sin tocar el `.env` ni desplegar, exclusivo de `mantenimiento` (sin permiso nuevo,
  `abort_unless(auth()->user()->isMaintenance())` en `mount()`, igual que la ruta). Hoy tres:
  **ubicación de la base** (empresa), **su radio de detección** (2026-09-14: petición del usuario
  — "el sistema tiene que ser inteligente para detectar si está en la base", ver Bloque 15) y
  **minutos mínimos de una parada no programada**. `App\Models\CompanySetting` — fila única
  (`current()` = `firstOrCreate([])`), `Auditable`. `App\Providers\AppServiceProvider::
  applyCompanySettings()` (llamado desde `boot()`) pisa `config('servalillo.base.*')`/
  `config('servalillo.dwell.exclude_base_radius_meters')`/`config('servalillo.dwell.
  unplanned_stop_min_seconds')` con estos valores en cada petición si no son `null`; si la tabla
  no existe todavía (antes de la primera migración) se traga el `QueryException` y se queda con
  el `.env`. Pensado para las pruebas iniciales del fichaje/paradas no programadas: fijar la base
  en un domicilio particular (con un radio generoso, ya que un domicilio no tiene el pin tan
  exacto como la nave real) y cambiarla luego sin desplegar. El mapa usa
  `<x-ui.geofence-map>` normal, con círculo/asa de radio — arrastrar el ámbar ajusta
  directamente `base_radius_meters`, más intuitivo que un input numérico suelto para "la zona en
  la que se considera que está en la base". **Historia corta (2026-09-13 → 2026-09-14):** este
  componente llegó a tener una prop `showRadius`/`:show-radius="false"` (un modo "solo el punto,
  sin círculo") porque al principio esta pantalla no necesitaba radio — un día después SÍ pasó a
  necesitarlo (este mismo campo) y esa prop se quedó sin ningún otro sitio que la usara, así que
  se revirtió por completo (Blade + `Alpine.data('geofenceMap')` en `app.js`) en vez de dejarla
  como código muerto. Si en el futuro hace falta un selector de solo-punto en algún otro sitio,
  reintroducirla es sencillo, pero no antes de que haya un caso de uso real.
- **Pendiente conocido (no es del Bloque 6):** la paginación de los listados Livewire sale en inglés
  ("Showing X to Y of Z results"). Livewire usa su propia vista `livewire::tailwind`, no la
  `resources/views/vendor/pagination/tailwind.blade.php` ya restilizada. Afecta también a los
  listados del Bloque 3. Se arregla apuntando Livewire a esa vista (o publicando
  `resources/views/vendor/livewire/tailwind.blade.php`) + un `lang/es.json` con las 4 cadenas.

