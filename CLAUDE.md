# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es esto

MVP de gestión de tareas y rutas de reparto para una flota de camiones cisterna (<10 camiones).
Monorepo: `server/` (Laravel 13 — API + panel web + web del chofer) y `mobile/` (Flutter, aún no
creado). Todo el desarrollo es **en local**. En la VPS, el document root de nginx es `server/public`.

**Dirección de UX confirmada para el Bloque 4** (panel de rutas): tablero estilo Trello — columnas =
rutas (camión+chofer) del día + columna fija "Sin asignar", tarjetas = paradas; en el chofer, una sola
columna = su ruta de hoy. Decisiones ya cerradas (fecha, columna sin asignar, swipe en móvil) en
`docs/03-ux-panel-rutas-kanban.md` — léelo antes de construir el Bloque 4.

El proyecto se construye por bloques numerados. El estado de cada bloque y el detalle de lo
implementado está en **`docs/02-estado-bloques.md`** — léelo antes de continuar. Las decisiones
de arquitectura y el modelo de datos están en `docs/01-arquitectura-y-modelo-datos.md`.

## Comandos (todos desde `server/`)

El entorno corre en Docker Compose (`server/compose.yaml`). Servicio de app = `laravel.test`.
`./vendor/bin/sail` es un alias de `docker compose`; ambos funcionan.

```bash
docker compose up -d                                   # levantar stack
docker compose exec laravel.test php artisan <cmd>      # artisan
docker compose exec laravel.test php artisan migrate:fresh --seed
docker compose exec laravel.test php artisan test                       # toda la suite (Pest)
docker compose exec laravel.test php artisan test --filter='nombre'     # un test
docker compose exec laravel.test php artisan test tests/Feature/Xyz.php  # un archivo
docker compose exec laravel.test ./vendor/bin/pint                      # formateo PHP
composer require <pkg>                                  # en el HOST (PHP 8.5 + Composer)
npm run dev                                             # Vite, en el HOST (Node 22)
npm run build
```

- App: http://localhost:8000 · Mailpit: http://localhost:8026 · Reverb: :8080 · Postgres: :5433
- BD de tests: base `testing` en el mismo Postgres (ya creada; `RefreshDatabase`).
- Usuarios seed (pass `password`): `admin@servalillo.test`, `soporte@servalillo.test` (mantenimiento), `pedro@servalillo.test` (chofer).

### Notas del entorno
- La imagen de app es **propia y ligera** (`server/docker/php/Dockerfile`), no la oficial de Sail
  (la oficial tardaba >30 min por el mirror de Ubuntu). Solo trae PHP + extensiones; **no trae Node**
  ni navegador headless. Vite y `composer` se ejecutan en el host.
- Ignora `server/CLAUDE.md` y `server/AGENTS.md`: son el stub de bootstrap de Laravel Boost, no
  guías reales. Boost **no** está instalado.

## Arquitectura

### Capas (requisito del proyecto, respétalas)
- **Validación**: en el panel Livewire (todo lo que hay hoy) se usan **`Livewire\Form` objects**
  (`app/Livewire/Forms/*Form.php`, uno por entidad: `rules()`, `messages()`, `save()`), NO
  `Illuminate\Foundation\Http\FormRequest` — no hay petición HTTP real de por medio en un ciclo Livewire.
  Los `FormRequest` de verdad se reservan para la API JSON (Bloque 9), reutilizando el mismo array de
  `rules()` donde tenga sentido. No confundir "no hay FormRequest classes" con "no se valida": la regla
  de "nunca confiar en input sin validar" se cumple igual, solo cambia la clase que lo hace.
- **Policies** por modelo (auto-descubiertas) para autorización. Comprueban permiso + propiedad.
- **Services** (`app/Services`) para lógica de negocio no trivial (ver `DeliveryTypeSchemaValidator`).
  Un CRUD simple NO necesita Service intermedio: la lógica vive directamente en el `Form` object.
- Componentes **Livewire de clase** para los CRUD (`app/Livewire/{Drivers,Trucks,Routes,Users}/Index.php`
  + `resources/views/livewire/.../index.blade.php`). Volt (single-file) solo en la auth de Breeze.
- Acceso a datos solo vía Eloquent / Query Builder con bindings. Nada de SQL en crudo con input.

### Roles y permisos (`spatie/laravel-permission`)
- 3 roles: `administrador`, `mantenimiento`, `chofer`. Guard `web`.
- Catálogo de permisos y asignación por rol: `database/seeders/RolePermissionSeeder.php`
  (constante `PERMISSIONS`). El seeder es la fuente de verdad — añade permisos ahí.
- `mantenimiento` = superusuario técnico vía `Gate::before` en `AppServiceProvider` (se sigue auditando).
- `administrador` = todo salvo `audits.view` / `system_logs.view`.
- `chofer` solo su operativa; las Policies comprueban que la ruta/parada es suya
  (`$user->driver->id === $route->driver_id`).
- Redirección post-login: ruta `home` → `dashboard` (gestión) o `chofer.today` (chofer).
- Middleware alias en `bootstrap/app.php`: `role`, `permission`, `role_or_permission`, `active`.
  `EnsureUserIsActive` va en el grupo `web` global.

### Modelo flexible de repartos (decisión validada — no cambiar sin acordarlo)
- `DeliveryType.field_schema` (JSONB): array de definiciones de campo `{key,label,type,required,options,unit,min,max}`.
- `RouteStop.data` (JSONB): los valores.
- **`App\Services\DeliveryTypeSchemaValidator` es el único punto** por el que deben pasar esos datos
  antes de persistirse. También valida la definición del schema en el CRUD de tipos.
- Tipos soportados: `text, textarea, number, date, select, boolean`.

### Canales de albarán (extensibles sin migración)
- Contrato `App\Contracts\DeliveryChannel`; implementaciones en `app/Support/DeliveryChannels/`
  (`EmailChannel`, `PhysicalChannel`); registro en `config/delivery.php`.
- `delivery_notes.delivery_channel` es un **string**, no un enum de BD. Añadir canal (p.ej. WhatsApp)
  = clase nueva + entrada en config. La entrega real se hará en un Job (Bloque 8).
- `physical` = no se envía nada, solo queda registrado (`delivered_at`, `delivered_by`).

### Auditoría y logs (para el panel de Mantenimiento, Bloque 6)
- `owen-it/laravel-auditing` en todos los modelos de negocio (interfaz `Auditable` + trait).
  No audita en consola/seeders; sí en web/API.
- Excepciones 5xx → tabla `error_logs` vía `App\Support\ErrorLogger` (registrado en
  `bootstrap/app.php` `withExceptions`). Tiene lista de exclusión (validación, auth, 4xx…).

### Enums
`app/Enums/` — `RouteStatus`, `RouteStopStatus`, `DeliveryNoteStatus`, `OdometerKind`, `ThemePreference`.
Backed enums con `->label()` en español; casteados en los modelos.

### Tiempo real
- Reverb. Canal `fleet-map` (privado, solo `isManager()`) definido en `routes/channels.php`.
- Broadcasting de posiciones GPS y mapa Vue: Bloques 3/9.

### Config propia
- `config/servalillo.php`: ventana de tracking GPS (pausa 22:00–05:00), intervalos, retención GPS 90 días;
  bloque `routing` (OSRM para "Ruta eficiente", Bloque 13); bloque `base` (ubicación de la nave, punto
  de salida "Desde la base" de "Ruta eficiente"); bloque `device` (`DEVICE_ENROLMENT_SECRET`, secreto
  de enrolamiento de la APK tracker, Bloque 10).
- `config/filesystems.php` disco `r2`: usa S3/R2 si hay `R2_ACCESS_KEY_ID`, si no cae a `local`
  (`storage/app/private/r2`). Firmas y PDFs van a este disco con nombres generados por el sistema.


## Índice de detalle (qué archivo de `docs/` leer para cada tema)

El detalle completo (decisiones, bugs reales corregidos, gotchas) vive en `docs/`. **Lee el archivo
correspondiente ANTES de tocar el área.** Cada archivo conserva el texto original, sin resumir.

| Si vas a tocar… | Lee |
|---|---|
| Estado de cada bloque / qué está hecho | `docs/02-estado-bloques.md` |
| Arquitectura y modelo de datos (diseño original) | `docs/01-arquitectura-y-modelo-datos.md` |
| UX del tablero Kanban (decisiones cerradas) | `docs/03-ux-panel-rutas-kanban.md` |
| Despliegue en VPS (runbook) | `docs/04-despliegue-vps.md` |
| Despliegue: gotchas de producción (`.env`, OPcache/PHP-FPM, seeds, APK release) | `docs/21-despliegue-notas-produccion.md` |
| Diseño del fichaje (diseño completo y legal) | `docs/05-fichaje.md` |
| Fichaje: notas de implementación (flags, permisos, rutas, exportes, totales) | `docs/20-fichaje-notas-implementacion.md` |
| Nóminas (estudio) | `docs/06-nominas-estudio.md` |
| Sistema de diseño: Tailwind, `.glass`/`.surface`, componentes `ui/*`, modales, tema claro/oscuro, date-input, bugs de errores de validación | `docs/07-sistema-diseno.md` |
| Panel Admin (chofers/camiones/usuarios), Kanban `/rutas` (SortableJS), Route permanente vs `RouteDay`, historial, estados | `docs/08-panel-admin-kanban-rutas.md` |
| Dashboard de estadísticas (`FleetStatsService`) y panel de Mantenimiento (auditoría, errores, logs, accesos, ajustes) | `docs/09-paneles-estadistico-y-mantenimiento.md` |
| Web del chofer `/chofer/ruta`, jornada, contador de litros, cierre de parada, reprogramar, gestionar/quitar paradas | `docs/10-web-chofer.md` |
| Albaranes, PDF, canales de entrega, firma | `docs/11-albaranes.md` |
| Clientes, calendario de reparto, planificar/suspender, importador, pre-clientes, `ServiceKind` | `docs/12-clientes.md` |
| Notificaciones (campana), observers vs dispatch explícito, soporte/tickets | `docs/13-notificaciones-soporte.md` |
| "Ruta eficiente" (OSRM), "Ver recorrido" (Leaflet, tiles) | `docs/14-ruta-eficiente.md` |
| API GPS (`/api/*`), dispositivos, APK tracker Flutter (`mobile/`) | `docs/15-api-gps-y-apk-tracker.md` |
| Diario de incidencias del chofer | `docs/16-diario-chofer.md` |
| Tiempo de permanencia, geocercas, paradas no programadas, `StopDwellService` | `docs/17-permanencia-en-parada.md` |
| Terminal vinculado (chofer sustituto) y captura de coordenadas por el chofer | `docs/18-terminal-sustituto-y-coordenadas.md` |
| Depósitos SGRA (`SgraClient`, Tailscale) | `docs/19-sgra.md` |
| Mapa de flota `/mapa`, Avisos `/avisos`, DNI/CIF al entregar, Facturas `/facturas`, logotipo, fichaje visible solo para mantenimiento | `docs/22-mapa-avisos-facturas-logo.md` |

### Reglas transversales que se deben recordar siempre
- Cualquier `ui/*` de campo nuevo: el error de validación se busca con la clave de `wire:model`
  (`form.campo`), no con `name` plano (ver `docs/07`).
- Modales: abrir/cerrar con `dispatch('open-modal'|'close-modal', 'nombre')`; sin scroll evitable en escritorio (`docs/07`).
- Carbon 3: `diffInSeconds()` NO es absoluto; comprobar el signo con un test (`docs/17`).
- Permiso nuevo en el seeder ⇒ re-ejecutar `RolePermissionSeeder` en local y producción (`docs/20`, `docs/21`).
- Tras editar el `.env` de producción a mano: `config:cache` + `systemctl reload php8.4-fpm` (`docs/21`).
- `RouteStop.route_id` apunta a `RouteDay`, no a la `Route` permanente (`docs/08`).
- Las tablas `attendances`/`attendance_corrections` nunca se purgan (`docs/20`).
- Mantenimiento lo ve todo siempre aunque un módulo esté apagado (`User::attendanceVisible()`); pantallas compartidas con administrador van en el grupo "Gestión", no bajo `/mantenimiento` (`docs/22`).
- Pint: formatear **solo los archivos tocados** (`pint <rutas>`), nunca `pint` a secas: reformatea ficheros ajenos.

- Código y comentarios de dominio en **español**; nombres de clases/métodos en inglés estándar Laravel.
- Regla de negocio: **1 camión = 1 ruta permanente vigente a la vez** (`Route::overlaps()`, sin fechas
  solapadas del mismo `truck_id` ni del mismo `driver_id`); cada ruta permanente genera como mucho
  **1 `RouteDay` por fecha** (índice único `route_days.route_id + route_date`).
- Tablas de alto volumen (`gps_positions`) y de log (`error_logs`) son append-only: `UPDATED_AT = null`, sin auditar.
- Los listados del panel deben tener búsqueda + filtro + orden + paginación, y en móvil convertirse en
  tarjetas (nada de scroll horizontal). Mobile-first.
- Registro público de usuarios está **deshabilitado** a propósito (los crea el admin).

## Tests
Pest. `tests/Pest.php` siembra `RolePermissionSeeder` en cada test Feature (`beforeEach`).
Factories: `User`, `Driver`, `Truck`, `DeliveryType`, `Route` (permanente), `RouteDay`, `RouteStop`.


## Despliegue (producción)
Runbook en `docs/04-despliegue-vps.md`; notas y gotchas en `docs/21-despliegue-notas-produccion.md`.
VPS bare-metal (nginx + PHP 8.4-FPM + PostgreSQL 16, sin Docker/Node/Reverb), ficheros en Cloudflare R2.
El deploy es `server/deploy/deploy.sh`, **ejecutado en el portátil** (los assets se compilan aquí y se suben).
