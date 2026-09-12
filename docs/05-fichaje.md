# Bloque 18 — Sistema de fichaje (entrada/salida)

> Estado: **diseño aprobado, sin implementar todavía**. Este documento es el plan de
> implementación; cuando se construya, esta cabecera pasa a "✅ Hecho" como el resto de bloques
> de `docs/02-estado-bloques.md`.
> Fecha: 2026-09-12

## Por qué

El usuario quiere que el personal de la empresa (no el suyo propio como gestor de la plataforma)
registre su jornada laboral, para cumplir el **registro de jornada obligatorio** (Real Decreto-ley
8/2019): hora de inicio y fin de cada día trabajado, conservado y disponible ante una posible
inspección, con cualquier corrección posterior **motivada y trazada**.

Existe un proyecto de referencia ya terminado y en producción del propio usuario,
`/home/sergio/VSC/Fichajes` (Laravel 11 + Inertia/Vue, multi-empresa con equipos Jetstream). Este
bloque **adapta su lógica de dominio** (modelo de datos, cálculo de horas, corrección
administrativa con auditoría) al stack y las convenciones de Gestión Servalillo — no porta su
stack ni su alcance completo. Se simplifica deliberadamente a lo que se ha pedido: nada de
pausas, multicanal (Telegram/WhatsApp/kiosko), historial laboral SCD-2 ni exportación XML a
medida — eso existe en el proyecto de referencia porque su alcance es distinto (gestoría
multi-empresa), no porque haga falta aquí.

**Se construye para quedar listo en producción pero desactivado** (`ATTENDANCE_ENABLED=false`)
hasta que el usuario decida activarlo — mismo patrón ya usado para los Depósitos SGRA (Bloque 17,
`config('servalillo.sgra.enabled')`).

## Decisiones de alcance (acordadas antes de diseñar)

| Decisión | Elegido | Por qué |
|---|---|---|
| Pausas dentro de la jornada | **No** | Pedido explícito: "sistema sencillo". Una pausa sin fichar es un problema más de auditar y corregir; se deja fuera de esta versión. |
| Ciclos de fichaje por día | **Uno**: una entrada y una salida | Simplifica el modelo a "como mucho una fila por persona y día" — nada de reabrir/cerrar tramos. |
| Quién ficha | **Administrador y chofer** — **mantenimiento NO** | Mantenimiento es el rol del propio usuario como gestor técnico de la plataforma, no un empleado de la empresa de reparto: no le aplica el registro de jornada. Sigue pudiendo **gestionar/corregir** el fichaje de los demás (superusuario técnico), pero no tiene fichaje propio. |
| Geolocalización | **Sí, con geovalla por chofer** | Los chofers pueden fichar lejos de la nave (donde dejan el camión aparcado). Cada chofer puede tener su propia zona habitual (centro + radio), configurada por administración; si no se configura, se usa la nave por defecto. |
| Fichaje fuera de zona | **Se permite, se marca** | Bloquear el fichaje por estar fuera de zona sería peor para el cumplimiento legal que dejarlo constar con un aviso — un registro dudoso siempre es mejor que ningún registro. Administración revisa los marcados, igual que un olvido. |
| Corrección de olvidos | **Con motivo obligatorio, en un ledger de solo-inserción** | Es el requisito explícito del usuario: "controlado, motivado y anotado en un sistema de auditoría". |

## Lo que se reutiliza del proyecto de referencia, y lo que no

**Se reutiliza (adaptado):**
- La forma de modelar un tramo de jornada: `in_at`/`out_at` nullable (`out_at` vacío = jornada
  abierta), con IP/coordenadas y marca de fuera-de-zona por cada evento por separado.
- El **ledger de correcciones separado del audit técnico genérico**: tabla propia, de
  solo-inserción (sin `updated_at`), con `reason` obligatorio y `old_values`/`new_values` — es la
  pieza que de verdad resuelve "controlado, motivado y anotado". El proyecto de referencia la
  llama `attendance_audits`; aquí se llama `attendance_corrections` para no confundirla con la
  tabla `audits` genérica que ya usa toda la app (`owen-it/laravel-auditing`).
- El criterio de que **la fila se sobrescribe** al corregirla (no se guarda una copia congelada
  "tal cual se fichó" aparte) — la evidencia legal la da el ledger de correcciones, no la
  inmutabilidad de la fila en sí. Es la práctica estándar y evita duplicar el modelo de datos.

**No se reutiliza / se deja fuera:**
- Pausas, multicanal, historial laboral SCD-2, geovallado "de oficina" genérico (aquí es por
  chofer, ver tabla de arriba), exportación XML a medida (se deja CSV, más simple).
- El cálculo de horas inline en un controlador — aquí sí hay un `Service` dedicado, siguiendo la
  convención ya establecida en Gestión Servalillo (`DeliveryTypeSchemaValidator`,
  `RouteOptimizer`, `StopDwellService`...).
- Un gap real detectado en el proyecto de referencia: **no había ninguna garantía explícita de
  conservación** de los fichajes (ni purga, pero tampoco una declaración de "esto no se borra
  nunca"). Aquí se deja explícito — ver "Cumplimiento legal" más abajo.

## Diseño

### Modelo de datos

**`attendances`** (nueva tabla) — un tramo de jornada:
- `id`
- `user_id` (FK `users`, `cascadeOnDelete`) — **ficha el `User`**, no el `Driver`: así sirve igual
  para administrador y chofer sin duplicar modelo. Mantenimiento nunca tendrá filas propias aquí
  (no ficha), pero sí puede aparecer como `created_by` (ver abajo) al dar de alta o corregir el
  fichaje de otro.
- `date` (date) — día natural del fichaje, fijado al crear la fila. Índice único **`(user_id,
  date)`**: aplica de raíz la regla "una entrada y una salida por día".
- `in_at` / `out_at` (timestamp, nullable) — `out_at IS NULL` = jornada abierta.
- `in_latitude` / `in_longitude` / `out_latitude` / `out_longitude` (decimal 10,7, nullable) —
  coordenadas del dispositivo en cada evento, si el navegador las da. **Nunca bloquean el
  fichaje** si no están disponibles (permiso denegado, sin soporte, sin señal).
- `in_out_of_bounds` / `out_out_of_bounds` (boolean, default `false`) — fuera de la zona permitida
  en ese evento concreto (ver geovalla).
- `total_seconds` (unsignedInteger, nullable) — `out_at->diffInSeconds(in_at)`, se calcula al
  fichar la salida. Sin redondeos: contabilidad precisa tal y como se pidió.
- `created_by` (FK `users`, `nullOnDelete`) — quién creó la fila: normalmente el propio usuario
  (fichaje real de su jornada), o un administrador/mantenimiento (alta manual de un día sin
  fichar). Mismo criterio ya usado en `DriverLog.created_by`/`updated_by` (Bloque 14): si
  `created_by !== user_id`, la fila nació de una gestión administrativa, sin necesitar una
  columna "origen" aparte.
- Implementa `Auditable` (`owen-it/laravel-auditing`), como el resto de modelos de negocio —
  auditoría técnica automática (quién, cuándo, IP, antes/después) visible en
  `/mantenimiento/auditoria`, en paralelo al ledger de correcciones de abajo.

**`attendance_corrections`** (nueva tabla) — el ledger de "controlado, motivado y anotado":
- `id`
- `attendance_id` (FK `attendances`, `cascadeOnDelete`)
- `corrected_by` (FK `users`) — quién hizo la corrección (administrador o mantenimiento).
- `old_values` (json) — estado antes de esta corrección (`[]` si la fila no existía todavía).
- `new_values` (json) — estado después.
- `reason` (text, **obligatorio**, sin valor por defecto).
- `created_at` únicamente — **sin `updated_at`** (`const UPDATED_AT = null` en el modelo): tabla
  de solo-inserción, igual que el proyecto de referencia.
- Modelo `App\Models\AttendanceCorrection`, **no** `Auditable` — es en sí mismo un registro de
  auditoría (auditar al auditor no aporta nada), mismo criterio que `GpsPosition`/`LoginLog`.

**`drivers`** (columnas nuevas, geovalla propia — nullable, la rellena administración si aplica):
- `attendance_latitude` / `attendance_longitude` (decimal 10,7, nullable)
- `attendance_radius_meters` (integer, nullable)
- A `null` (caso normal: chofer que siempre sale de la nave) → se usa la geovalla por defecto. Se
  rellenan solo para el chofer que aparca habitualmente en otro sitio, tras comunicarlo a
  administración (tal y como planteó el usuario).

### Config e interruptor de activación

Nuevo bloque en `config/servalillo.php`, mismo patrón que `sgra` (Bloque 17):

```php
'attendance' => [
    'enabled' => (bool) env('ATTENDANCE_ENABLED', false),
    // Geovalla por defecto = la nave (config('servalillo.base'), ya existe — es el mismo origen
    // que usa "Ruta eficiente"). Nadie configura nada para el caso normal de oficina.
    'default_radius_meters' => (int) env('ATTENDANCE_DEFAULT_RADIUS_M', 150),
],
```

Con `enabled` en `false` (por defecto):
- El enlace "Fichar" no aparece en el nav.
- Cada componente Livewire del bloque aborta con 404 en `mount()` si el flag está apagado (mismo
  patrón `abort_unless` que `Dashboard\Index`/`Sgra\Index`) — una URL directa tampoco cuela.
- Las migraciones **sí** se despliegan (tablas creadas, vacías): activar en el futuro es solo
  cambiar `ATTENDANCE_ENABLED=true` en el `.env` de producción y desplegar, sin migración nueva.

### `App\Services\AttendanceService`

Único punto de la lógica de negocio:
- `punchIn(User $user, ?float $lat, ?float $lng): Attendance` — busca/crea la fila de **hoy**
  (`firstOrNew(['user_id' => ..., 'date' => today()])`); si ya tiene `in_at`, aborta (ya fichó hoy
  — esta validación vive aquí, no solo en el índice único de BD). Calcula `in_out_of_bounds` si
  hay coordenadas, comparando contra `effectiveGeofence($user)`.
- `punchOut(User $user, ?float $lat, ?float $lng): Attendance` — exige que exista la fila de hoy
  con `in_at` y `out_at` vacío (si no, aborta: "no has fichado la entrada hoy"); calcula
  `total_seconds` y `out_out_of_bounds`.
- `effectiveGeofence(User $user): array{lat: float, lng: float, radius: int}` — si el usuario
  tiene `driver` con `attendance_latitude` relleno, usa eso; si no, cae a
  `config('servalillo.base.*')` + `default_radius_meters`.
- `correct(Attendance $attendance, array $newValues, string $reason, User $correctedBy): Attendance`
  y `createManual(User $target, array $values, string $reason, User $createdBy): Attendance` —
  ambos recalculan `total_seconds` si hay ambas horas y **siempre** insertan una fila en
  `attendance_corrections` con el `old_values`/`new_values` exactos antes de devolver. La
  validación real de que `reason` no esté vacío vive en el `Form` object correspondiente; el
  servicio la vuelve a comprobar (cinturón y tirantes, dado el peso legal de esta pieza).
- Distancia entre dos puntos: reutiliza **`App\Support\Haversine`** (ya existe, la usa
  `RouteOptimizer` como fallback sin OSRM) — no hace falta escribir la fórmula otra vez.

### Autorización

- Permiso nuevo `attendance.manage` en `RolePermissionSeeder::PERMISSIONS` (ver y corregir el
  fichaje de cualquiera): administrador lo recibe por la regla ya existente ("todo salvo
  audits.view/system_logs.view"), mantenimiento por su `Gate::before` de superusuario técnico —
  sin tocar nada más.
- Fichar la propia jornada y ver el propio historial **no necesita permiso nuevo** — basta con
  estar autenticado y que el flag esté activo (igual que la campana de notificaciones, que ve
  todo el mundo).
- `App\Policies\AttendancePolicy` (auto-descubierta): `view($viewer, $attendance)` = es su propio
  fichaje O tiene `attendance.manage`; `manage()` = `attendance.manage`.

### Interfaz

- **`/fichar`** (`App\Livewire\Attendance\Index`, ruta `attendance.index`) — página personal de
  fichaje: estado de hoy (sin fichar / trabajando desde HH:MM / jornada cerrada, X h Y min — con
  `App\Support\Duration::humanShort()`, ya existe, se reutiliza), un botón grande "Fichar
  entrada"/"Fichar salida" (capta `navigator.geolocation.getCurrentPosition`, mismo patrón que
  `Chofer\Today::captureStopCoordinates`/`tracker-test.blade.php` — sin coordenadas, ficha
  igualmente, nunca bloquea por un problema técnico de ubicación) y una tabla con el histórico
  propio (últimos 30 días, `<x-ui.table>`, `.surface`, nunca `.glass`). Visible solo para
  administrador y chofer (mantenimiento no tiene fichaje propio, ver tabla de decisiones).
- Enlace de navegación **"Fichar"**: `auth()->user()->hasRole('administrador')` en el nav de
  gestión (mismo patrón ya usado para el icono de Soporte) y siempre para el chofer, junto a "Mi
  ruta". **Mantenimiento no ve este enlace.** Gateado además por
  `@if (config('servalillo.attendance.enabled'))`.
- **`/mantenimiento/fichajes`** (`App\Livewire\Maintenance\Attendance`, permiso
  `attendance.manage`) — nueva pestaña en `<x-maintenance.tabs>` (una línea más en el array
  `$tabs`, mismo patrón que "Dispositivos"/"Accesos"): listado de todos los fichajes (buscar por
  persona, filtrar por mes), con avisos visuales de fichajes **sin salida** (jornada abierta desde
  hace más de X horas) y **fuera de zona**. Acciones:
  - **"Corregir"** sobre una fila existente → modal con `in_at`/`out_at` + `reason` obligatorio →
    `AttendanceService::correct()`.
  - **"Registrar fichaje olvidado"** (día sin ninguna fila) → mismo modal, elige persona + fecha +
    horas + `reason` → `AttendanceService::createManual()`.
  - **"Ver correcciones"** por fila → modal de solo lectura con el ledger de esa fila (quién,
    cuándo, motivo, antes/después) — versión curada, igual criterio que el modal "Ver cambios" del
    Diario de incidencias (Bloque 14). La auditoría técnica completa sigue disponible sin filtrar
    en `/mantenimiento/auditoria` (se añade `'Fichaje' => Attendance::class` a `Audits::MODELS`).
  - **Exportar CSV** del rango visible (`league/csv`, ya es dependencia del proyecto desde el
    import de clientes): persona, fecha, hora entrada, hora salida, horas trabajadas, si hubo
    corrección y por qué.

### Cumplimiento legal (RD-ley 8/2019) — cómo lo cubre este diseño

- **Registro diario de hora de inicio y fin**: `attendances.in_at`/`out_at`.
- **Conservación** (mínimo 4 años exigido por ley): **no existe ni existirá un comando de purga**
  para `attendances` ni `attendance_corrections` — a diferencia de `gps_positions`
  (`gps:purgar`, 90 días) o `login_logs`/`error_logs` (con su propia retención configurable),
  estas dos tablas quedan **explícitamente exentas** de cualquier purga automática, presente o
  futura. Si se toca esta zona en el futuro, **no meterlas** en ningún comando genérico de
  limpieza de datos.
- **Accesible al trabajador**: cada usuario ve su propio histórico en `/fichar`.
- **Accesible a la Inspección de Trabajo**: exportación CSV desde `/mantenimiento/fichajes`.
- **Trazabilidad de correcciones**: ledger `attendance_corrections` de solo-inserción con motivo
  obligatorio y quién corrigió, más la auditoría técnica automática ya existente en el proyecto
  como segunda capa independiente.

## Ficheros (cuando se implemente)

**Nuevos**
- `server/database/migrations/..._create_attendances_table.php`
- `server/database/migrations/..._create_attendance_corrections_table.php`
- `server/database/migrations/..._add_attendance_geofence_to_drivers_table.php`
- `server/app/Models/Attendance.php`, `server/app/Models/AttendanceCorrection.php`
- `server/app/Services/AttendanceService.php`
- `server/app/Policies/AttendancePolicy.php`
- `server/app/Livewire/Attendance/Index.php` + `resources/views/livewire/attendance/index.blade.php`
- `server/app/Livewire/Maintenance/Attendance.php` + `resources/views/livewire/maintenance/attendance.blade.php`
- `server/resources/views/components/attendance/correct-modal.blade.php`,
  `.../corrections-history-modal.blade.php`
- `server/tests/Feature/AttendanceTest.php` (fichar entrada/salida, una vez al día, geovalla,
  mantenimiento sin acceso a fichaje propio), `server/tests/Feature/AttendanceCorrectionTest.php`
  (motivo obligatorio, ledger, permisos, alta manual de un día sin fichar)

**Modificados**
- `server/config/servalillo.php` — bloque `attendance`.
- `server/database/seeders/RolePermissionSeeder.php` — permiso `attendance.manage`.
- `server/routes/web.php` — rutas `fichar` y `mantenimiento/fichajes`.
- `server/resources/views/livewire/layout/navigation.blade.php` — enlace "Fichar" (administrador +
  chofer, escritorio + móvil).
- `server/resources/views/components/maintenance/tabs.blade.php` — pestaña nueva.
- `server/app/Livewire/Maintenance/Audits.php` (donde viva `Audits::MODELS`) — añadir
  `'Fichaje' => Attendance::class`.
- `server/app/Livewire/Forms/DriverForm.php` + vista de `/chofers` — campos opcionales de geovalla
  propia (lat/lng/radio), visibles/editables solo por quien tenga `attendance.manage`.
- `server/.env.example` / `.env.production.example` — documentar `ATTENDANCE_*`.
- `CLAUDE.md` — sección nueva (Bloque 18) describiendo el bloque, con énfasis en la exención de
  purga.

## Cómo probar (cuando se implemente)

1. `docker compose exec laravel.test php artisan test --filter=Attendance`, luego la suite
   completa.
2. Con `ATTENDANCE_ENABLED=true` en local: fichar entrada/salida como chofer y como
   administrador; intentar fichar la entrada dos veces el mismo día (debe rechazarlo); fichar
   desde una ubicación lejana a la nave (debe permitirlo pero marcarlo fuera de zona); entrar como
   mantenimiento y comprobar que **no** ve "Fichar" en el nav ni tiene fichaje propio, pero sí
   `/mantenimiento/fichajes`; desde ahí, corregir un fichaje sin motivo (debe rechazarlo), con
   motivo (debe guardar el ledger y verse en "Ver correcciones"), dar de alta un día sin fichar, y
   exportar CSV.
3. Desplegar con `ATTENDANCE_ENABLED=false` (interruptor apagado) y confirmar que no aparece nada
   en el nav ni las URLs son accesibles — el "queda preparado para activarlo" pedido.

## Pendiente / fuera de alcance de esta primera versión

- Pausas dentro de la jornada (comida, descansos) — descartado a propósito, ver tabla de
  decisiones.
- Múltiples ciclos de entrada/salida el mismo día — descartado a propósito.
- Exportación en PDF o en un XML "a medida" para inspección — el proyecto de referencia lo tiene
  (pensado para su contexto de gestoría multi-empresa); aquí un CSV es suficiente y mucho más
  simple. Se podría añadir más adelante si hiciera falta.
- Notificaciones automáticas de "jornada abierta demasiado tiempo" (el proyecto de referencia las
  tiene vía Telegram/email) — se puede añadir reutilizando el sistema de notificaciones in-app ya
  existente (Bloque 12) si se echa en falta una vez activado.
