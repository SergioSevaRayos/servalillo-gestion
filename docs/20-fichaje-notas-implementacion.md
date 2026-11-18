# Sistema de fichaje (Bloque 18) — notas de implementación

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Sistema de fichaje (Bloque 18, 2026-09-12) — construido, desactivado en producción

- **Diseño completo en `docs/05-fichaje.md`** (léelo antes de tocar esta zona). Analizado y
  adaptado de un proyecto ya terminado, `/home/sergio/VSC/Fichajes` (Laravel+Inertia, multi-tenant)
  — se reutilizó el modelo de tramo entrada/salida y el ledger de correcciones de solo-inserción;
  se dejó fuera pausas, multicanal y el historial laboral SCD-2 (no pedidos).
- **Ficha el `User`, no el `Driver`**: `administrador` y `chofer` fichan; **`mantenimiento` no**
  (es el gestor técnico de la plataforma, no personal de la empresa) —
  `User::canPunchAttendance()`. `attendances` (`user_id`+`date` único, `in_at`/`out_at`
  nullable, coordenadas y "fuera de zona" por evento, `total_seconds`) +
  `attendance_corrections` (ledger append-only, `reason` obligatorio, `UPDATED_AT = null`) —
  mismo patrón que `LoginLog`/`GpsPosition` para lo que no se audita a sí mismo.
- **Geovalla por usuario**: `users.attendance_mode` (`base`|`remote`) +
  `attendance_latitude/longitude/radius_meters`. Un chofer que aparca fuera de la nave puede tener
  su propia zona; por defecto usa `config('servalillo.base')`. Fichar fuera de zona **nunca se
  bloquea** (bloquear sería peor para el cumplimiento legal que fichar marcado) — solo se marca
  para que administración lo revise. `AttendanceService::effectiveGeofence()` +
  `App\Support\Haversine::meters()` (reutilizado, no se repitió la fórmula).
- **`config('servalillo.attendance.enabled')`** en `false` por defecto — mismo patrón
  "feature flag, listo pero apagado" que `sgra.enabled`. Cada componente Livewire aborta 404 en
  `mount()` si está apagado; el nav no muestra ningún enlace.
- **Acceso del trabajador a su propio registro NUNCA lleva casilla ni permiso** — decisión
  explícita del usuario tras corregir el plan original: RD-ley 8/2019 da al trabajador derecho a
  ver/exportar su propia jornada, así que una casilla que administración pudiera usar para
  quitárselo sería un riesgo de incumplimiento real. `Attendance\Index` (`/fichar`) solo exige
  `canPunchAttendance()`, sin permiso adicional.
- **`/fichajes/gestion` (no `/mantenimiento/fichajes`)**: error de diseño real en el plan
  original, detectado al implementar. `/mantenimiento/*` tiene middleware `role:mantenimiento`
  **en exclusiva** en `routes/web.php` — un panel que administrador también necesita no puede ir
  ahí. Se colocó como última ruta dentro del grupo `role:administrador|mantenimiento` ("Gestión"),
  y `/fichar` fuera de cualquier grupo de rol (solo `auth`), porque administrador y chofer viven en
  grupos de middleware distintos. **Si se añade en el futuro cualquier otra pantalla que administrador
  y mantenimiento compartan, va en el grupo "Gestión", nunca bajo `/mantenimiento`.**
- **Conservación: `attendances`/`attendance_corrections` quedan explícitamente exentas de
  cualquier purga automática**, presente o futura (a diferencia de `gps_positions`,
  `login_logs`/`error_logs`, que sí se purgan) — el RD-ley 8/2019 exige ~4 años de conservación.
  **No metas estas dos tablas en un futuro comando genérico de limpieza de datos.**
- Exportación: `AttendanceExportService::toLegalXml()` (XML `RegistroJornada`, `SimpleXMLElement`
  puro, sin dependencia nueva) y `toPdfRows()` + `pdf/attendance-report.blade.php`
  (`barryvdh/laravel-dompdf`, Helvetica, mismo patrón que `pdf/delivery-note.blade.php`).
  `config('servalillo.company.{name,tax_id}')` alimenta la cabecera de ambos.
- `Audits::MODELS` incluye `'Fichaje' => Attendance::class` (auditoría técnica automática,
  complementaria al ledger de correcciones, que es la versión "para leerse" con el motivo).
- **Desplegado con `ATTENDANCE_ENABLED=false`**: migraciones sí corren (tablas creadas, vacías);
  activar en el futuro es cambiar el flag en el `.env` de producción y desplegar, sin migración
  nueva.
- **Gestión de fichajes ajenos** (`/fichajes/gestion`): "Corregir" (ajustar horas), "Cerrar
  ahora"/"Reabrir" (atajos que prefijan la salida a ahora o la vacían, mismo modal y misma
  validación — no son endpoints aparte) y "Ver ubicación" (mapa de solo lectura con los puntos de
  entrada/salida sobre la geovalla de la persona, `<x-attendance-location-modal>`). Revisado
  contra el proyecto de referencia (`/home/sergio/VSC/Fichajes`,
  `CompanyController::updateAttendance()`): ahí tampoco hay endpoints separados para
  cerrar/reabrir, es la misma corrección de `in_at`/`out_at`/`reason` — se siguió el mismo
  criterio en vez de inventar acciones nuevas.
- **Gotcha real de este bloque (2026-09-12): permisos nuevos en el seeder no llegan solos a una
  BD de desarrollo ya sembrada.** Añadir `'attendance.manage'`/`'sgra.view'` a
  `RolePermissionSeeder::PERMISSIONS` no los mete en la BD local hasta volver a ejecutar el
  seeder — `migrate` sin `--seed` no lo hace, y una sesión ya logueada tampoco se refresca sola.
  Sin ese paso, los enlaces gateados con `@can(...)` en el nav simplemente no aparecen (parecía un
  bug del código cuando en realidad el permiso no existía todavía para ese usuario). Cada vez que
  se añada un permiso nuevo al seeder durante desarrollo local: `docker compose exec laravel.test
  php artisan db:seed --class=RolePermissionSeeder --force` (idempotente, usa `findOrCreate` +
  `syncPermissions`, seguro de repetir). **El mismo gotcha se repitió en producción** al desplegar
  este bloque (`deploy.sh` corre `migrate --force`, nunca seeders) — `administrador` se quedó sin
  `attendance.manage`/`sgra.view` reales hasta correr `ssh $VPS "cd .../server && php artisan
  db:seed --class=RolePermissionSeeder --force"` a mano. **Añade este paso al propio `deploy.sh`
  o a la checklist de despliegue** la próxima vez que un cambio meta un permiso nuevo en el
  seeder — no basta con haberlo corregido en local.
- **Segundo gotcha real, encadenado con el anterior (mismo día, al activar `ATTENDANCE_ENABLED`
  para probar en caliente): el reseed sí funcionó, pero el usuario real seguía sin ver nada.**
  Causa distinta — OPcache de PHP-FPM sirviendo config vieja tras editar el `.env` a mano; ver el
  gotcha general en "Despliegue (producción)" (`systemctl reload php8.4-fpm`). Los dos juntos son
  la lección completa de esta sesión: **tras tocar el `.env` o el seeder de permisos en producción
  fuera de `deploy.sh`, hacen falta AMBOS pasos** (reseed de permisos si aplica + reload de
  PHP-FPM), y verificarlo con una sesión real, no con `tinker`/`curl` sin sesión.
- **Exportaciones (XML y PDF) incluyen la coordenada del dispositivo** de cada evento (entrada y
  salida) — la capta el propio navegador al fichar, no se recalcula ni se inventa.
- **`App\Services\GeocodingService`** (buscador de direcciones sobre `<x-ui.geofence-map>`):
  único punto que habla con Nominatim (OpenStreetMap), con `User-Agent` identificando la app
  (exigido por su política de uso) — nunca se llama a esa API directo desde el navegador. Mismo
  criterio "Service = único punto de contacto externo, nunca lanza al llamador" que
  `SgraClient`/`RouteOptimizer`.
- **`/fichajes/totales`** (`App\Livewire\Attendance\Totals`, misma pestaña de gestión que
  `/fichajes/gestion` vía `<x-attendance.tabs>`, mismo permiso `attendance.manage`): tabla
  comparativa horas/días por persona para un periodo (día/semana/mes/año + fecha ancla), con
  detalle día a día por persona en modal. Toda la agregación vive en
  `App\Services\AttendanceStatsService` — único punto de cálculo, reutilizado también por los 4
  KPI de `/fichar` (hoy/semana/mes/año), así que el número que ve un chofer siempre coincide con
  el que ve administración para él. **"Días" = jornadas *cerradas*, no un equivalente de 8h** —
  los chofers no tienen jornada fija, así que un día de 3h y uno de 14h cuentan igual como "1
  día" (decisión explícita del usuario, que rechazó la idea de un equivalente de 8h). Una jornada
  **abierta nunca suma** a ningún total (solo se muestra aparte como "en curso" en `/fichar`, o
  marcada visiblemente y excluida en el detalle día a día) — el jornal no puede depender de una
  estimación de una jornada sin terminar. Semana = **ISO, lunes-domingo** (`Carbon::MONDAY`/
  `Carbon::SUNDAY` explícitos, nunca el default de locale). `App\Support\Duration::decimalHours()`
  añade el formato "172,25 h" (coma española) que pide nómina, sin tocar `humanShort()`.
- **`attendance_mode = 'external'` — huella en base (2026-09-13)**: un chofer en este modo ficha
  con lector de huella en la base a través de **otro sistema, ya implementado, sin ninguna
  relación con esta app** (ni API ni importación — decisión explícita del usuario). Efecto:
  `User::canPunchAttendance()` da `false` para él (sin "Fichar" en el nav, 403 si visita `/fichar`
  directo) y queda excluido de `AttendanceStatsService::comparisonTable()`/`Manage::eligibleUsers()`
  — mostrarlo con "0 horas" sería engañoso. Se configura desde el mismo selector "Modo" de
  `/chofers`/`/usuarios`, sin geovalla ni mapa (igual que `base`). **Si algún día se integra ese
  sistema externo, el punto de entrada natural es un importador hacia `attendances`** (mismo
  criterio que `ClientImporter`, Bloque 9) — no existe todavía, es solo el hueco más razonable.
- **Auditoría legal del formato de datos (2026-09-13)**: `docs/05-fichaje.md`, sección "Auditoría
  del formato de datos frente a la ley", tiene la comparación campo a campo contra la ley vigente
  (34.9 ET, ya se cumple) y contra el Proyecto de Ley de reducción de jornada (nuevo art. 34 bis
  ET, **todavía no aprobado, sin fecha de entrada en vigor**) — léela antes de dar por bueno o por
  urgente cualquier cambio "porque lo va a exigir la ley pronto": la interoperabilidad real con la
  ITSS (acceso remoto en tiempo real) sigue dependiendo de un reglamento que aún no existe.
- **Adaptación al proyecto de ley (2026-09-13)** — `docs/05-fichaje.md`, sección "Adaptación al
  proyecto de ley — implementado 2026-09-13", detalle completo. Resumen: (1) `users.
  weekly_contracted_hours` (nullable, umbral general `ATTENDANCE_DEFAULT_WEEKLY_HOURS`=40 si no se
  rellena) + `AttendanceStatsService::dailyBreakdownWithHours()` desagregan horas
  ordinarias/extraordinarias por semana ISO (sin "complementarias", no ofrecida como opción; sin
  pausas, el usuario mantuvo el "sistema sencillo") — visible en `/fichajes/totales`, el PDF y el
  XML/JSON exportados. (2) El XML/PDF/JSON llevan ahora también los segundos en crudo junto al
  texto legible, y el motivo/quién/cuándo de la ÚLTIMA corrección (los 2 "hallazgos de bajo coste"
  de la auditoría, resueltos). (3) `GET /fichajes/exportar.json`
  (`AttendanceExportController::json()`, permiso `attendance.manage`) — un endpoint de exportación
  genérico, **explícitamente NO una integración real con la ITSS** (no existe spec todavía): mismo
  contenido enriquecido que el XML/PDF, en JSON, listo para adaptar cuando exista el reglamento.
- **Horas de hoy/semana/mes/año "en directo" en `/fichar` (2026-09-14)**: antes, con una jornada
  abierta, las 4 tarjetas se quedaban congeladas mostrando el total sin esa jornada (p. ej. "Hoy:
  0 min" recién fichada la entrada) hasta recargar la página — parecía roto. Ahora, mientras hay
  jornada abierta, se le suma en vivo el tiempo transcurrido con un contador Alpine (`tick()` cada
  15 s desde `startedAt`, sin petición al servidor). **No cambia el cálculo de nómina**: la base
  (cerrada) sigue siendo exactamente `AttendanceStatsService` — esto es solo una capa visual sobre
  el propio `/fichar` del trabajador; `/fichajes/totales` (administración) no se toca.
  `wire:ignore` en el bloque (el mismo gotcha que el carrusel de días: cambiar el string `x-data`
  en cada commit de Livewire reiniciaría el contador), así que `Index::punchIn()`/`punchOut()`
  avisan del cambio con un evento explícito (`attendance-times-updated`, con los segundos base de
  cada periodo + `startedAt` o `null`) en vez de dejar que el morph lo actualice solo.
