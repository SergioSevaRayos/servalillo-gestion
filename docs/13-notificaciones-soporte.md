# Notificaciones y canal de soporte (Bloque 12)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Notificaciones y canal de soporte (Bloque 12)
- **Notificaciones = sistema nativo de Laravel**, tabla `notifications` (`php artisan notifications:table`,
  editada: `data` es **`jsonb`**, PK `uuid` **intacta** — `Illuminate\Notifications\DatabaseNotification`
  la exige, no crear modelo propio). Canal **`database` únicamente** (sin mail, sin broadcast), envío
  **síncrono** (las clases usan `Queueable` pero **no** `ShouldQueue`). `User` ya tiene `Notifiable`.
- **5 clases en `app/Notifications/`** (`ChoferRouteChanged`, `SupportTicketOpened`,
  `SupportTicketReplied`, `SupportTicketStatusChanged`, `UnplannedStopDetected`). Todas devuelven en `toArray()` el **mismo
  esquema** para que la campana pinte cualquiera: `type`, `title`, `body`, `url`, `icon`
  (`route|wrench|chat|flag` → `<svg>` inline en `bell.blade.php`). El deep link de soporte lo resuelve
  el trait `Concerns\LinksToTicket` según el rol del `$notifiable` (admin → `/soporte`, resto →
  `/mantenimiento/soporte`).
- **Campana** = `App\Livewire\Notifications\Bell` (componente de **clase**, no Volt) **anidado dentro
  del componente Volt de navegación** (`livewire/layout/navigation.blade.php`, cluster derecho). **La
  ve todo el mundo** (antes solo `isManager()` — desde el Bloque 14 el chofer también recibe avisos,
  ver más abajo). `wire:poll.30s` (coherente con chofer 15s / tablero 45s). Su vista
  tiene raíz única `<div wire:poll.30s>`; el nav persiste entre `wire:navigate` (vive fuera de `$slot`)
  así que la campana y su poll sobreviven. `markRead($id)` marca leída y `$this->redirect(url, navigate:
  true)`.
- **Notificaciones oficina → chofer (Bloque 14): SÍ observer, deliberadamente.** Cuando oficina
  gestiona una parada de la ruta de un chofer desde el panel (añade/modifica/quita/reasigna),
  `App\Observers\RouteStopObserver` (registrado con `#[ObservedBy]` en `RouteStop`) le manda un
  `App\Notifications\StopManagedByOffice` (`kind` = `added|modified|removed`, `url` → `chofer.today`
  de esa fecha). Es la **excepción** a la regla de abajo: aquí el criterio es inequívoco (`auth()->
  user()->isManager()` — actúa oficina, nunca el chofer) y hay que cubrir **cualquier** camino
  (tablero, arrastre, "+ Añadir", auto-asignación por fecha, código futuro), cosa que un puñado de
  dispatch sueltos no garantiza. Un reordenado (solo `position`) **no** notifica. La generación
  automática de paradas recurrentes se silencia con `RouteStopObserver::muted(fn () => …)` en
  `RecurringStopService` (es sistémica, no "gestión"). No se puede probar en Pest que el `Audit` de
  ese cambio se cree (`console => false`), pero la notificación sí (usa `Notification::fake()`).
- **Disparo de las notificaciones chofer → oficina = dispatch explícito, NUNCA observer.** Los 6 tipos
  (`stop_failed`, `stop_skipped`, `stop_rescheduled`, `client_added`, `meter_discrepancy`,
  `stop_removed` — este último, 2026-09-24, ver "Quitar una parada equivocada" en el Bloque 7) no se
  distinguen de un `updated`/`created` genérico y un observer se dispararía también para el tablero de
  oficina. `App\Support\Notifications\RouteChangeNotifier` se llama desde `StopActionForm::apply()`
  (rama failed/skipped, tras el `->update()`), `Today::addClientStop()`, `Today::endDay()` (solo rama
  `$adjusted`) y `Today::removeStop()`. Guard: `! auth()->user()?->isDriver()` → seeders/comandos/
  tablero no disparan nada. **Entrega completada normal y empezar/terminar jornada sin incidencia NO
  notifican.** `StopActionForm::apply()` recibe ahora un 3er argumento `RouteChangeNotifier` (el único
  llamador, `Today::saveStop`, lo pasa a mano con `app(...)`). Destinatarios:
  `RouteChangeNotifier::recipients()` → `User::role(['administrador', 'mantenimiento'])` (ver el bug
  corregido el 2026-09-24 justo debajo, en la nota de parada no programada).
- **Notificación sistémica: parada no programada detectada → administración (2026-09-14).**
  Tercer patrón de disparo, distinto de los dos anteriores (observer para oficina→chofer,
  dispatch explícito para chofer→oficina): aquí no hay ni un usuario "actuando" ni un evento
  Eloquent — es el propio `StopDwellService::run()` el que, tras reconstruir `unplanned_stops`
  del día (borra + reinserta, ver Bloque 15), envía `App\Notifications\UnplannedStopDetected`
  a `User::role(['administrador', 'mantenimiento'])->where('is_active', true)->get()` (mismos
  destinatarios que `RouteChangeNotifier::recipients()`) por cada parada no programada nueva.
  **Bug real corregido (2026-09-24): mantenimiento no recibía NINGUNA de estas notificaciones**
  (ni las de `RouteChangeNotifier` — fallo/omisión/reprogramación/cliente añadido/descuadre de
  litros/parada quitada, ni esta de parada no programada) — ambos puntos usaban
  `User::role('administrador')` a secas, sin `mantenimiento`, pese a que `User::isManager()`
  (`hasAnyRole(['administrador', 'mantenimiento'])`) documenta explícitamente que **"mantenimiento
  y administrador comparten el acceso completo de gestión"**. Detectado por el usuario al probar
  "Quitar una parada" (arriba) con una cuenta de mantenimiento y no ver el aviso en la campana.
  **El problema
  específico de este patrón**: `run()` se llama constantemente (cron nocturno + recálculo
  perezoso en cada vista de Board/Today/History) y siempre borra+reinserta la tabla entera, así
  que sin más no habría forma de distinguir "parada ya avisada" de "parada nueva" — notificaría
  la misma parada en cada poll. Se resolvió con una columna `notified_at` en `unplanned_stops`
  que `StopDwellService::carryOverNotifications()` **conserva entre recálculos emparejando por
  `entered_at`** (identidad estable de una parada no programada concreta — no cambia aunque
  `left_at`/`seconds` sí lo hagan mientras sigue abierta): las filas nuevas heredan el
  `notified_at` de la fila anterior con el mismo `entered_at` si existía, y solo las que
  siguen `null` tras el emparejamiento son "de verdad nuevas" — `notifyNewUnplannedStops()`
  las notifica y les pone `notified_at = now()` justo después de insertarlas. Una notificación
  por parada (no una por recálculo), incluida una que sigue abierta (se avisa igual, con
  "todavía en curso" en el cuerpo) — no se vuelve a avisar cuando se cierra más tarde, porque
  `entered_at` no cambió. `url` → `routes.board` con la fecha de esa ruta (mismo patrón que
  `ChoferRouteChanged`).
- **Canal de soporte** = tickets con hilo. `support_tickets` (asunto + `category` enum `SupportCategory`
  fallo/necesidad/consulta + `status` enum `SupportStatus` abierto→en_curso→resuelto + `body` +
  `last_reply_at`, softDeletes) + `support_ticket_replies` (cascade). `SupportTicket` **NO es
  `Auditable`** a propósito. Enums en `app/Enums/Support*.php`; `SupportStatus::badgeVariant()`.
- **Permisos**: `support.create` (admin + mantenimiento), `support.manage` (solo mantenimiento — se
  quita de la exclusión del admin en `RolePermissionSeeder`). `SupportTicketPolicy` fina: `update`/
  `delete` del creador **solo mientras el otro lado no haya respondido**
  (`replies()->where('user_id','!=',$user->id)->exists()`); mantenimiento pasa siempre por
  `support.manage` (+ `Gate::before`).
- **Lado admin**: `App\Livewire\Support\Index` en `/soporte` (`permission:support.create`) — lista de
  **sus** incidencias + modal de alta + panel de hilo con respuestas y editar/borrar el propio mensaje.
  **Lado mantenimiento**: `App\Livewire\Maintenance\Support` en `/mantenimiento/soporte` (4ª pestaña,
  `permission:support.manage`) — todas las incidencias, filtros, modal de hilo, `setStatus()`, borrar.
  Añadir la pestaña = una línea en `resources/views/components/maintenance/tabs.blade.php`.
- **Notificaciones del soporte** vía `App\Support\Notifications\SupportNotifier`: alta → mantenimiento;
  respuesta → el otro lado; cambio de estado → el creador. Sin guard de consola (solo se llama desde
  acciones Livewire reales; el seeder usa `->notify()` directo).
- Push instantáneo con Reverb = mejora futura (la infra existe pero Echo no está cableado).

