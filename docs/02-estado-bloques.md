# Estado de los bloques

| # | Bloque | Estado |
|---|---|---|
| 0 | Arquitectura + modelo de datos | ✅ Validado |
| 1 | Backend base (auth, roles, modelo, migraciones, seeders) | ✅ Hecho |
| 2 | Sistema de diseño Tailwind (claro/oscuro, componentes, gota animada) | ✅ Hecho |
| 3 | Panel Administrador (CRUDs Livewire) | ✅ Hecho |
| 4 | Tablero Kanban de rutas + drag & drop de paradas | ✅ Hecho |
| 5 | Panel estadístico (KPIs + gráficos) | ✅ Hecho |
| 6 | Panel de Mantenimiento (logs + auditoría) | ✅ Hecho |
| 7 | Web operativa del Chofer | ✅ Hecho |
| 8 | Albaranes (PDF + canales email/físico) + colas | ✅ Hecho |
| 9 | Gestión de clientes (CRUD + ficha + histórico + import Access) | ✅ Hecho |
| 10 | API de tracking GPS (Sanctum) | ✅ Hecho |
| 11 | APK Flutter de tracking (headless) + `GET /api/device` | ✅ Hecho |
| 12 | Notificaciones in-app + canal de soporte administración ↔ mantenimiento | ✅ Hecho |
| 13 | Ruta eficiente (optimización de paradas, OSRM + fallback local) | ✅ Hecho |
| 14 | Diario de incidencias del chofer | ✅ Hecho |
| 15 | Tiempo de permanencia en parada (geocerca por GPS) | ✅ Hecho |
| 16 | Terminal vinculado a una ruta (chofer sustituto) | ✅ Hecho |
| 17 | Depósitos SGRA (integración externa, visual 3D) | ✅ Hecho |

> Los Bloques 12 y 13 se construyeron por delante de 10/11 a petición del usuario (igual que el 9).
> Los Bloques 14-17 no estaban en el `docs/01` original: son funciones pedidas sobre la marcha una vez
> la app ya estaba en producción, numeradas en el orden en que se construyeron.

> El Bloque 9 original era "API Flutter (Sanctum)"; el usuario intercaló la gestión de clientes
> por delante, así que la API pasa a ser el Bloque 10 y el tracking el 11.

> **Re-alcance del Bloque 10 (2026-09-08):** la app Flutter NO es una app del chofer. Es una **APK
> "tracker" sin interfaz** que el servicio técnico instala en el móvil de cada camión. Así que el
> Bloque 10 quedó como **solo la API de ingesta GPS** + gestión de dispositivos en el panel. Toda la
> operativa del chofer que planteaba `docs/01` §4 (rutas/paradas/albaranes) ya la cubre la web Livewire.

---

## Bloque 1 — lo que se ha construido

### Entorno local
- **Docker Compose** (`server/compose.yaml`), manejable con `./vendor/bin/sail` o `docker compose`.
- Imagen de app propia y ligera (`server/docker/php/Dockerfile`, PHP 8.4 + `pdo_pgsql`) en vez de la imagen oficial de Sail — la oficial tardaba +30 min por el mirror de Ubuntu. Node/Vite corre en el **host**.
- Servicios: `laravel.test` (8000), `pgsql` (5433), `mailpit` (8026), `reverb` (8080), `queue` (worker).

### Paquetes instalados
`laravel/sanctum`, `spatie/laravel-permission`, `owen-it/laravel-auditing`, `laravel/reverb`, `laravel/breeze` (stack Livewire, solo como base de auth).

### Autenticación
- Login con **rate limiting** (5 intentos), CSRF, comprobación `is_active`, registro de `last_login_at`.
- **Registro público deshabilitado** (las cuentas las crea el Administrador).
- Redirección por rol: chofer → `/chofer/ruta`, gestión → `/dashboard`.
- Middleware `EnsureUserIsActive` global: desactivar un usuario lo expulsa en su siguiente request.
- Recuperación de contraseña por email (Mailpit en local).
- Política de contraseñas: mínimo 10, letras + números (+ `uncompromised` en producción).

### Roles y permisos (Spatie)
- 3 roles: `administrador`, `mantenimiento`, `chofer`.
- 30 permisos granulares (ver `RolePermissionSeeder::PERMISSIONS`).
- `administrador`: todo salvo `audits.view` y `system_logs.view`.
- `mantenimiento`: todo (además de un `Gate::before` que lo hace superusuario técnico, **sin dejar de auditar**).
- `chofer`: solo su operativa (`routes.view.own`, `deliveries.complete`, `deliveries.record_signature`, `odometer.record`, `delivery_notes.view`).
- **Policies** por modelo, auto-descubiertas, con comprobación permiso + propiedad para el chofer.

### Modelo de datos (migraciones + modelos + casts + relaciones)
`users` (+ `phone`, `is_active`, `theme_preference`, `last_login_at`, soft-deletes), `drivers`, `trucks`, `truck_assignments`, `devices`, `delivery_types` (con `field_schema` JSONB), `routes` (único `truck_id`+`route_date`), `route_stops` (con `data` JSONB + `position` para drag&drop), `delivery_notes` (canal como string), `odometer_readings`, `gps_positions` (append-only), `error_logs`, `audits`.

### Modelo flexible de repartos
- `DeliveryType.field_schema` (JSONB) define los campos de cada tipo.
- `RouteStop.data` (JSONB) guarda los valores.
- `App\Services\DeliveryTypeSchemaValidator` es el **único** punto de validación de esos datos (nunca se confía en el cliente). También valida la propia definición del schema.

### Canales de albarán extensibles
- `App\Contracts\DeliveryChannel` + `EmailChannel` / `PhysicalChannel` + `DeliveryChannelManager`.
- Registro en `config/delivery.php`. Añadir WhatsApp = una clase nueva, sin migración.

### Auditoría
- `owen-it/laravel-auditing` activo en todos los modelos de negocio.
- No audita en consola/seeders (comportamiento deseado). Sí audita en peticiones web/API.
- Excepciones de sistema (5xx) se persisten en `error_logs` vía `App\Support\ErrorLogger` (con lista de exclusión para 4xx, validación, auth…).

### Seeders
- 6 usuarios (1 admin, 1 mantenimiento, 4 chóferes), 4 camiones + dispositivos, 4 choferes, 2 tipos de reparto (gasóleo / agua) con schema real, 3 rutas de ejemplo (en curso / publicada / completada de ayer) con 12 paradas y lecturas de contador.

### Tests
`./vendor/bin/sail artisan test` → **30 passed**. Cubre login/roles/policies/redirecciones/usuario inactivo + auth de Breeze adaptado.

---

## Cómo probar el Bloque 1

```bash
cd server
docker compose up -d
docker compose exec laravel.test php artisan migrate:fresh --seed
npm run dev            # en otra terminal, en el host
```

1. Abre http://localhost:8000 → **Log in**.
2. `admin@servalillo.test` / `password` → entra al **dashboard** de gestión.
3. Cierra sesión, entra como `pedro@servalillo.test` / `password` → te lleva a **/chofer/ruta** (placeholder de momento). Si intentas `http://localhost:8000/dashboard` → **403**.
4. Recuperación de contraseña: pulsa "¿Olvidaste tu contraseña?", introduce un email, y mira el correo en **http://localhost:8026** (Mailpit).
5. Auditoría: cambia tu nombre en **/profile** estando logueado y comprueba en `tinker`:
   ```
   docker compose exec laravel.test php artisan tinker
   >>> OwenIt\Auditing\Models\Audit::latest()->first()->getModified();
   ```
6. Tests: `docker compose exec laravel.test php artisan test`.

---

## Bloque 2 — lo que se ha construido

### Aclaración importante
Breeze instaló **Tailwind v3** (postcss + `tailwind.config.js` real), no v4 — una nota de la sesión
anterior decía lo contrario, era un error. `@tailwindcss/vite` está en `package.json` pero **sin usar**
(residuo del template de Breeze); el pipeline real es `laravel-vite-plugin` + `postcss.config.js`.

### Sistema de diseño (`tailwind.config.js` + `resources/css/app.css`)
- Paleta `primary` (azul-petróleo, 50-950) propia; semánticos = escalas nativas de Tailwind
  (emerald=success, amber=warning, rose=danger). Neutros = `slate`.
- Tipografía: pila de **fuentes de sistema** (`-apple-system`, Segoe UI, Roboto…), sin CDN de fuentes
  (se quitó la dependencia de fonts.bunny.net de Breeze — cero llamadas de red para tipografía).
- Radios (`sm/DEFAULT/md/lg/xl/2xl`) y sombras (`shadow-soft-*`, `shadow-glass`) centralizados.
- Modo claro/oscuro con `darkMode: 'class'`. Dos composiciones en `@layer components`:
  `.glass` (chrome flotante: nav, modales, KPIs, toasts, selector de tema) y `.surface` (sólido, alto
  contraste: formularios, tablas, web del chofer) — la regla de dónde usar cada una está en el comentario
  del propio CSS.
- `.table-responsive` + `[data-label]`: patrón tabla→tarjetas en móvil sin JS (ver `<x-ui.table>`).

### Componentes Blade reutilizables (`resources/views/components/ui/`)
`button`, `input`, `select`, `textarea`, `checkbox`, `badge` (con `RouteStatus/RouteStopStatus/
DeliveryNoteStatus::badgeVariant()`), `card` (sólido), `glass-panel`, `stat-card` (KPI cristal), `table`,
`theme-toggle`, `toast-container`, `drop-menu`. Los primitivos de Breeze (`x-text-input`, `x-input-label`,
`x-input-error`, `x-primary/secondary/danger-button`, `x-dropdown`, `x-modal`, `x-nav-link`,
`x-responsive-nav-link`, `x-application-logo`) se **restilizaron in-place** (mismos nombres/props) para
que las páginas de auth de Breeze/Volt quedaran con el diseño nuevo sin tocar su lógica.

### Modo claro/oscuro con persistencia
- `resources/views/partials/theme-init.blade.php`: script inline en `<head>` que aplica el tema antes de
  pintar (evita FOUC), leyendo la cookie `theme` o, si no existe, `theme_preference` del usuario.
- `Alpine.store('theme')` en `resources/js/app.js`: cambia la clase `dark`, escribe la cookie y hace
  `POST /theme` (fire-and-forget) para persistir en BD si hay sesión.
- `ThemeController` + `UpdateThemeRequest`: la cookie `theme` está **excluida del cifrado**
  (`bootstrap/app.php` → `encryptCookies(except: ['theme'])`) porque JS la lee/escribe directamente.
- `<x-ui.theme-toggle>`: selector de 3 vías (claro/automático/oscuro) accesible (`role="radiogroup"`).

### Botón de menú móvil — gota animada
`<x-ui.drop-menu>` (usado en `livewire/layout/navigation.blade.php`, solo `md:hidden`): una gota en
reposo se cruza-desvanece con tres gotas más pequeñas que aparecen escalonadas (`delay-0/75/150`) usando
`ease-[cubic-bezier(0.34,1.56,0.64,1)]` (back-out, sensación de rebote) — solo `opacity`/`transform`,
nada de `backdrop-filter` animado (barato en batería). Al abrir despliega un panel `.glass` con los
enlaces según el rol.

### Limpieza
- Registro público (`pages.auth.register`), la welcome de Laravel y `livewire/welcome/navigation` se
  **eliminaron** (dead code: `/` ya redirige a `/login`).
- `lang/es/{auth,passwords,validation,pagination}.php` añadidos: la app no tenía ningún fichero de
  idioma propio y todo el texto de sistema (errores de validación, "email enviado", paginación) salía
  en inglés pese a `APP_LOCALE=es`.
- Páginas de auth (login, forgot/reset/confirm-password, verify-email) y los tres sub-formularios de
  `/profile` traducidos a español y con soporte oscuro.

### Página de demostración
`/style-guide` (rol administrador/mantenimiento): muestra todos los componentes de golpe — útil para
QA visual y como referencia antes de construir el Bloque 3.

### Tests
`DesignSystemSmokeTest` (dashboard con `.glass`, `/style-guide` renderiza sin errores, chofer sin
cristal) + `ThemePreferenceTest` (cookie sin cifrar, persistencia en BD, valor inválido rechazado).
Total suite: **36 passed**.

---

## Cómo probar el Bloque 2

```bash
cd server
docker compose up -d
npm run dev            # en el host, para ver los cambios de Tailwind al vuelo
```

1. `http://localhost:8000/login` → tarjeta sólida centrada, selector de tema arriba a la derecha.
   Cambia entre claro/automático/oscuro: no debe haber parpadeo al recargar la página.
2. Entra como `admin@servalillo.test` / `password` → nav superior con efecto cristal, 4 tarjetas KPI.
3. Ve a **Guía de estilo** en el menú → repasa botones, badges, formulario, tabla, modal, toasts.
4. Reduce el ancho de la ventana (o abre en el móvil):
   - La tabla de la guía de estilo se convierte en tarjetas apiladas (sin scroll horizontal).
   - Aparece el botón de **gota** fijo abajo a la derecha; al pulsarlo se abre en 3 gotas con rebote
     y despliega el menú.
5. `docker compose exec laravel.test php artisan test` → 36 passed.

---

## Bloque 3 — lo que se ha construido

### Alcance
CRUD completo (buscar + filtrar + ordenar + paginar + crear/editar/eliminar, con Policies) para:
**Chofers** (`/chofers`), **Camiones** (`/camiones`), **Rutas** (`/rutas`, solo la ficha: fecha, camión,
chofer, estado, nombre, notas — sin gestión de paradas todavía) y **Usuarios de personal**
(`/usuarios`, solo administrador/mantenimiento).

### Decisión de arquitectura: validación con Livewire `Form` objects, no `FormRequest`
El proyecto pide "Form Requests para validar toda entrada". En una app Livewire-first como esta, las
peticiones que rellenan estos CRUD **no pasan por un Controller con un `$request` HTTP real** — Livewire
gestiona su propio ciclo de vida. La adaptación fiel al espíritu del requisito (una clase dedicada,
reutilizable y testeable que encapsula `rules()`, mensajes y el guardado, separada de la vista) son los
**Livewire `Form` objects**: `App\Livewire\Forms\{Truck,Driver,User,Route}Form`. Los `FormRequest` de
verdad (`Illuminate\Foundation\Http\FormRequest`) se reservan para la API JSON del Bloque 9, donde sí hay
una petición HTTP real de por medio; ahí se puede reutilizar exactamente el mismo array de `rules()`.

### Chofers vs Usuarios — límite de responsabilidad
Un chofer **es** un `User` (rol `chofer`) + un `Driver`. Para evitar dos pantallas escribiendo la misma
fila de `users`: **Chofers** crea/edita el usuario+perfil de conductor; **Usuarios** gestiona
exclusivamente cuentas `administrador`/`mantenimiento` y no lista ni permite tocar chofers. El cambio de
rol en `/usuarios` solo puede alternar entre esos dos roles de personal (protegido además por el permiso
`roles.manage` vía `UserPolicy::assignRoles`).

### Patrón reutilizado en los 4 módulos
- Componente Livewire de clase (`#[Layout('layouts.app')]`, `WithPagination`) con `search`/`sort`/
  `direction`/filtro en `#[Url]` (persisten en la URL, se puede compartir/recargar el enlace).
- Modal (`<x-modal>`) abierto/cerrado vía eventos de navegador `open-modal`/`close-modal` (el patrón real
  de Breeze) en vez de un booleano Livewire ligado a `:show` — un booleano no sirve porque Alpine conserva
  su estado a través de los re-renders de Livewire (morph), así que cambiar la prop no reabre el modal.
- Borrado con `wire:confirm` (nativo de Livewire 3, sin modal propio) + `$this->dispatch('toast', ...)`.
- `<x-ui.sortable-th>` (nuevo) para cabeceras de columna ordenables; `<x-ui.empty-state>` (nuevo) para
  listados vacíos.
- Vista de paginación restilizada: `resources/views/vendor/pagination/tailwind.blade.php` (publicada y
  reescrita con los tokens del Bloque 2; es la vista que Laravel usa por defecto, no requiere registro).

### Tests
`TrucksCrudTest`, `DriversCrudTest`, `UsersCrudTest`, `RoutesCrudTest` (Livewire::test, con
`assertHasErrors`/`assertHasNoErrors` para las reglas de negocio: código/matrícula/empleado/email únicos,
"1 camión = 1 ruta/día") + 2 tests nuevos en `DesignSystemSmokeTest`. Total suite: **50 passed**.
Helper `makeUser($role)` centralizado en `tests/Pest.php` (antes duplicado en dos archivos).

---

## Cómo probar el Bloque 3

```bash
cd server && docker compose up -d && npm run dev
```

1. Entra como `admin@servalillo.test` / `password`. En la nav aparecen **Rutas, Chofers, Camiones,
   Usuarios**.
2. **Camiones**: crea uno, edítalo, bórralo. Prueba a repetir un código → error inline en el modal.
3. **Chofers**: crea uno con email+contraseña → comprueba que puede iniciar sesión y le lleva a
   `/chofer/ruta`. Edítalo dejando la contraseña en blanco → no cambia.
4. **Rutas**: crea una para un camión en una fecha ya usada por otra ruta → error "1 camión = 1 ruta/día".
5. **Usuarios**: solo debe aparecer personal (admin/mantenimiento), nunca chofers.
6. En cualquier listado: busca, cambia el orden clicando una cabecera, filtra por estado, reduce la
   ventana — la tabla se convierte en tarjetas.
7. `docker compose exec laravel.test php artisan test` → 50 passed.

---

## Bloque 4 — lo que se ha construido

### La vista principal de "Rutas" ahora es el tablero
`GET /rutas` (`routes.board`, componente `App\Livewire\Routes\Board`) es el tablero Kanban:
**columnas = rutas del día seleccionado** (camión + chofer + badge de estado) **+ columna fija
"Sin asignar"**, **tarjetas = paradas** (`route_stops`). Selector de fecha con ‹Hoy› y flechas
anterior/siguiente. La ficha CRUD del Bloque 3 (fecha/camión/chofer/estado/notas de la ruta en sí)
se movió a `GET /rutas/listado` (`routes.index`); ambas vistas se enlazan entre sí.

### Modelo de datos
- Migración `2026_09_06_090000_make_route_stops_route_id_nullable`: `route_stops.route_id` ahora es
  **nullable**, con `nullOnDelete()` en vez de `cascadeOnDelete()`. `RouteStop::scopeUnassigned()` =
  `whereNull('route_id')`.
- `Route` usa `SoftDeletes`, así que el FK `nullOnDelete` no salta al borrar. `Routes\Index::delete()`
  saca a mano las paradas **pendientes** a "Sin asignar" antes del `->delete()` (las cerradas se van
  con la ruta). Sin esto quedaban huérfanas e invisibles.
- La columna "Sin asignar" es un **backlog global, no filtrado por fecha** (una parada sin ruta no tiene
  fecha propia hasta que se le asigna una ruta que sí la tiene).

### Crear/editar paradas — aquí es donde se usa el modelo flexible de repartos por primera vez
`App\Livewire\Forms\RouteStopForm` + modal "+ Añadir parada" en cada columna. Al elegir un
`delivery_type_id`, el formulario **renderiza dinámicamente** los campos de su `field_schema`
(`text/textarea/number/date/select/boolean`) y los valores se validan y limpian con el
`DeliveryTypeSchemaValidator` del Bloque 1 antes de guardarlos en `route_stops.data` — es la primera
pantalla real que ejercita ese servicio. Pinchar una tarjeta abre el mismo modal en modo edición
(con botón eliminar).

### Drag & drop — SortableJS (no el plugin `@alpinejs/sort`)
Se eligió **SortableJS** en vez de la alternativa oficial de Alpine por tener una API de arrastre
multi-columna (`group`) que se puede documentar con total certeza; instalado vía npm e importado en
`resources/js/app.js` (función `initKanbanColumns`).
- Cada `<ul data-stop-list>` es una columna; `data-route-id` (ausente = "Sin asignar") identifica el
  destino. El evento `onEnd` de Sortable calcula el nuevo orden de la(s) columna(s) afectada(s) leyendo
  `data-stop-id` de los `<li>` y dispara un `CustomEvent('stops-reordered')` en `window`.
- El componente Livewire escucha ese evento (`x-on:stops-reordered.window="$wire.call('reorderStops', ...)"`)
  y persiste: reindexa `position` 1..n en la columna destino y, si hubo cambio de columna, también en la
  de origen. Valida que la ruta destino exista y que todas las paradas sigan existiendo (`abort` 422 si no).
- **Por qué no hace falta reinicializar Sortable tras cada respuesta de Livewire**: los `<li>` llevan
  `wire:key` estable y el nuevo orden que devuelve el servidor coincide con el que ya dejó el arrastre en
  el DOM, así que el morph de Livewire no toca esos nodos. `initKanbanColumns` se llama también en
  `livewire:navigated` y en el hook `Livewire.hook('morph.updated', ...)` por si aparecen columnas nuevas
  (cambio de fecha, nueva ruta) — con una guarda `list._sortable` para no inicializar dos veces.

### Móvil: una columna a la vez con swipe (decisión ya validada en docs/03)
Contenedor de columnas con `snap-x snap-mandatory` + cada columna `snap-start` a ancho ~88vw; una barra
de pestañas encima (una por columna) hace scroll suave a la columna correspondiente. En escritorio las
columnas son de 320px fijos en fila, sin snap.

### Tests
`RoutesBoardTest` (10 tests): visibilidad por rol, columnas correctas, crear parada en una columna y en
"Sin asignar", reasignación por `reorderStops`, reindexado dentro de una columna, rechazo de ruta destino
inexistente, rechazo de mover una parada completada, reindexado que respeta paradas completadas ya
colocadas, borrado de parada. Total suite: **62 passed**.

### Ajustes de UX del mismo día (tras la primera revisión)
- **Solo las paradas "Pendiente" se pueden arrastrar.** No tiene sentido reasignar una parada ya
  completada o fallida a otro camión/chofer. Filtro doble: `filter: '[data-draggable="false"]'` en
  SortableJS (cliente, `resources/js/app.js`) + comprobación real en `Board::reorderStops()` (servidor):
  si la posición/ruta de una parada cambiaría y su estado no es `Pending`, aborta con 422. Nunca fiarse
  solo del filtro de cliente.
- Las tarjetas no arrastrables se pintan con un **tono apagado** (fondo grisáceo, texto atenuado,
  `grayscale-[0.3]`) en vez de un candado — decisión explícita del usuario, mejor UX que un icono.
- **Se eliminó por completo el estado `RouteStopStatus::InProgress` ("En curso")** de las paradas — a
  reconsiderar más adelante si hace falta. Sigue existiendo `RouteStatus::InProgress` (el de la *ruta*
  completa), que es un concepto distinto y no se ha tocado.
- **Comportamiento Trello real**: el tablero no crece verticalmente. Cada columna tiene altura fija
  (`h-[65vh] md:h-[calc(100vh-14rem)]`), con cabecera y botón "+ Añadir parada" fijos y la lista de
  tarjetas con su propio scroll interno (`flex-1 min-h-0 overflow-y-auto`). El scroll para moverse entre
  columnas sigue siendo horizontal (`overflow-x-auto` en el contenedor de columnas).

---

## Cómo probar el Bloque 4

```bash
cd server && docker compose up -d && npm run dev
```

1. Entra como `admin@servalillo.test` / `password` → **Rutas** en la nav lleva ahora al tablero.
2. Verás columnas para las rutas de hoy (las que trae el seed) + "Sin asignar" vacía.
3. "+ Añadir parada" en "Sin asignar" → rellena cliente, elige un tipo de reparto (Gasóleo/Agua) → deben
   aparecer los campos específicos de ese tipo (litros, producto, forma de pago…) → guarda.
4. Arrastra esa tarjeta desde "Sin asignar" hasta la columna de una ruta → suéltala → recarga la página:
   el cambio debe persistir (comprueba que sigue en esa columna, no ha vuelto a "Sin asignar").
5. Reordena dos tarjetas dentro de la misma columna arrastrando → recarga → el orden debe mantenerse.
6. Marca una parada como "Completada" desde el modal → guarda → comprueba que sale apagada y que ya
   **no se puede arrastrar** (ni dentro de su columna ni a otra).
7. Añade varias paradas a una misma ruta hasta que no quepan verticalmente → comprueba que la columna
   saca su propio scroll vertical y el resto del tablero (cabecera, otras columnas) no se mueve.
8. Pincha una tarjeta → edítala o bórrala desde el modal.
9. Cambia la fecha con las flechas o el selector → las columnas cambian a las rutas de ese día.
10. Reduce la ventana por debajo de 768px → usa la barra de pestañas superior o haz swipe horizontal para
    moverte entre columnas (una visible a la vez).
11. Enlace "Gestionar fichas de ruta →" lleva al CRUD clásico (`/rutas/listado`) para crear la ruta del
    día si no existe ninguna.
12. `docker compose exec laravel.test php artisan test` → 62 passed.

---

## Bloque 5 — lo que se ha construido

**Panel estadístico del Administrador**, montado sobre `/dashboard` (que deja de ser un `Route::view`
y pasa a ser el componente Livewire `App\Livewire\Dashboard\Index`).

- **`App\Services\FleetStatsService`** — punto único de agregación. `report($from, $to)` devuelve:
  - `kpis`: rutas del periodo, rutas activas hoy, entregas completadas/falladas, tasa de éxito, litros
    entregados, % de cumplimiento, paradas sin asignar, camiones/chóferes activos.
  - `operations`: serie diaria de paradas completadas vs falladas + desglose de estados de ruta.
  - `volume`: litros planificados vs entregados (paradas cerradas) + desglose por tipo de reparto.
  - `by_driver`: completadas / falladas / tasa de fallo / litros por chofer.
  - `by_truck`: días con ruta / km (por lecturas de odómetro inicio-fin) / litros / capacidad.
  - Todo con agregación en Postgres (`count(*) filter (where ...)`), excluyendo soft-deletes a mano.
- **`Index` (Livewire)**: `#[Url] $range` en `{7d,30d,90d,year}` (def. `30d`), `abort_unless` con
  `stats.view` en `mount()`, y `permission:stats.view` en la ruta. `setRange()` reemite `stats-updated`.
- **Chart.js** (`chart.js@4`, fijado, `import ... from 'chart.js/auto'` en `app.js`). 4 gráficos
  (línea de paradas/día, donut de estados de ruta, donut de litros por tipo, barras apiladas por
  chofer) en un bloque `wire:ignore`; el componente Alpine `window.statsCharts` los crea una vez y
  los actualiza por evento. Las instancias **no** se guardan en el estado de Alpine (Proxy reactivo
  sobre Chart.js → stack overflow) — detalle en `CLAUDE.md`.
- Vista: `resources/views/livewire/dashboard/index.blade.php`. KPIs con `<x-ui.stat-card>` (`.glass`),
  gráficos y tablas con `<x-ui.card>` (`.surface`) + `<x-ui.table>` con `data-label` para móvil.
- **Seeder**: `DatabaseSeeder::seedHistory()` genera ~90 días de rutas pasadas (completadas y alguna
  cancelada, con paradas, cantidades, fallos y odómetro). Guarda de idempotencia por fecha.
- Tests: `tests/Feature/FleetStatsServiceTest.php` (8, cálculos del service) y
  `tests/Feature/DashboardStatsTest.php` (7, acceso + rango + KPIs). Total suite: **76 passed**.

### Cómo probar el Bloque 5

```bash
cd server && docker compose up -d && npm run build
docker compose exec laravel.test php artisan migrate:fresh --seed
```

1. Entra como `admin@servalillo.test` → caes en `/dashboard` (Panel estadístico).
2. Cambia el rango (7 días / 30 / 90 / Año) → KPIs, gráficos y tablas se actualizan; el rango queda
   en la URL (`?range=7d`).
3. Cambia el tema claro/oscuro → los gráficos siguen legibles sin recargar.
4. `soporte@servalillo.test` (mantenimiento) también entra; un chofer recibe 403.
5. `docker compose exec laravel.test php artisan test` → 76 passed.

---

## Bloque 6 — lo que se ha construido

**Panel de Mantenimiento** (`/mantenimiento`, exclusivo del rol `mantenimiento`). 3 sub-vistas con
pestañas compartidas (`<x-maintenance.tabs>`):

- **Auditoría** (`App\Livewire\Maintenance\Audits`, `/mantenimiento/auditoria`): lista de `Audit`
  (`owen-it/laravel-auditing`) con filtros — búsqueda por usuario/modelo, tipo de modelo (constante
  `Audits::MODELS`), evento, rango de fechas. Modal con `getModified()` (campo / antes / después) +
  URL / IP / user-agent.
- **Errores del sistema** (`Errors`, `/mantenimiento/errores`): lista de `ErrorLog` (5xx que captura
  `App\Support\ErrorLogger`). Filtros + modal con la traza (`context['trace']`). Acciones: eliminar
  uno y **purgar los de > 30 días**.
- **Log de la aplicación** (`SystemLog`, `/mantenimiento/log`): visor de `storage/logs/*.log`. Lee
  solo la cola del archivo (512 KB), parte las entradas por la cabecera con fecha, filtra por nivel
  y texto. `safePath()` bloquea path traversal (solo `*.log` dentro de `storage/logs`).

- Enlace "Mantenimiento" en el nav solo con `isMaintenance()` (nuevo helper en `User`).
- El auditing está desactivado en consola, así que el seeder inserta ~35 filas de `audits` y 9
  `ErrorLog` de ejemplo a mano (`seedMaintenanceData()`, con guarda de idempotencia).
- Tests: `tests/Feature/MaintenancePanelTest.php` (10: acceso + filtros + purga + path traversal).
  **Suite total: 86 passed.**

### Cómo probar el Bloque 6

1. Entra como `soporte@servalillo.test` (mantenimiento) → aparece "Mantenimiento" en el nav.
2. Auditoría: filtra por modelo "Camión" y evento "Modificado"; abre "Ver" en una fila.
3. Errores: busca "Reverb", abre el detalle, prueba "Purgar > 30 días".
4. Log: cambia de nivel a "Error", busca texto, despliega una entrada para ver la traza.
5. Como `admin@servalillo.test` → `/mantenimiento/*` da 403.

**Pendiente conocido:** la paginación de los listados Livewire (Bloques 3 y 6) sale en inglés —
Livewire usa su vista propia, no la `vendor/pagination/tailwind.blade.php` restilizada. Fix pequeño y
transversal, aún sin hacer.

---

## Bloque 7 — lo que se ha construido

**Web operativa del Chofer** (`/chofer/ruta`, `App\Livewire\Chofer\Today`). Su ruta de hoy en una
columna de paradas. Mobile-first, `.surface` siempre, nunca `.glass`.

- **Ciclo de jornada, controlado por el chofer:**
  - "Empezar jornada" → lectura de odómetro de inicio (`OdometerService::recordStart`) → ruta a
    `InProgress`. Las paradas no se operan hasta empezar.
  - "Terminar jornada" → odómetro de fin (`OdometerService::recordEnd`, **fija `trucks.odometer`**) →
    ruta a `Completed`. Muestra resumen inicio / fin / km.
  - `App\Services\OdometerService` valida no-negativo, inicio ≥ odómetro del camión, fin ≥ inicio.
- **Cierre de parada** (`App\Livewire\Forms\StopActionForm`): Entregada (litros + campos del
  `field_schema`, validados por `DeliveryTypeSchemaValidator`) / Fallida / Cancelada (con motivo →
  `failure_reason`; el estado se guarda como `skipped` pero se etiqueta "Cancelada"). Se puede
  reabrir una parada cerrada. **No toca `delivery_notes`** (Bloque 8).
- `<x-chofer.stop-card>` = tarjeta táctil grande (sin drag), distinta de la del Kanban. Cada parada
  **pendiente** lleva botones **▲/▼** al lado (`Today::moveStop`) para subirla/bajarla una posición
  a mano; las cerradas no se mueven.
- 13 tests nuevos (`tests/Feature/ChoferTodayTest.php`). **Suite total: 99 passed.**

### Cómo probar el Bloque 7

1. Entra como `lucia@servalillo.test` → su ruta de hoy sale como "Publicada", sin empezar.
2. "Empezar jornada" → introduce un contador ≥ el del camión → la ruta pasa a "En curso".
3. Toca una parada → "Entregada" (ajusta litros y campos) / "Fallida" / "Cancelada" con motivo.
4. `pedro@servalillo.test` ya tiene la jornada empezada (1 parada hecha, 3 pendientes).
5. "Terminar jornada" → contador de fin → comprueba que el odómetro del camión se actualiza.
6. `carlos@servalillo.test` no tiene ruta hoy → estado vacío.

---

## Bloque 8 — lo que se ha construido

**Albaranes** (`delivery_notes`), enganchado al cierre de parada del chofer.

- **PDF = dompdf** (`barryvdh/laravel-dompdf`), no Spatie: el contenedor no tiene navegador headless.
  Vista `resources/views/pdf/delivery-note.blade.php`, fuente Helvetica (PDF de ~2 KB).
- **`DeliveryNoteService::createForStop()`**: al entregar una parada crea el `delivery_note` (número
  `ALB-{año}-{6 díg}` secuencial, `customer_snapshot` congelado, firma PNG validada por cabecera
  mágica y guardada en disco `r2`), estado `Queued`, y despacha `ProcessDeliveryNote`.
- **`ProcessDeliveryNote`** (job, cola `database`): `Generating` → PDF a `r2` (`Generated`) →
  `$channel->deliver()` (`Sent` / `DeliveredPhysically`). Reintenta 3×; `failed()` → `Failed`.
- **Canales**: `EmailChannel` envía `DeliveryNoteMail` con el PDF adjunto (Mailpit en local);
  `PhysicalChannel` solo marca entregado. `config/delivery.php` sin cambios estructurales.
- **Chofer**: el modal de "Entregada" incluye canal + email + firmante + **firma en canvas**
  (`signature_pad`, `<x-ui.signature-pad>`).
- **Admin**: `/albaranes` (`App\Livewire\DeliveryNotes\Index`) con filtros + "Reprocesar" + "Marcar
  entregado"; descarga de PDF en `GET /albaranes/{note}/pdf` (manager o chofer dueño).
- Seeder: un albarán por cada parada completada del historial (~990), estados variados.
- 13 tests nuevos (service + job + listado + descarga + validación de firma/email). **Suite: 113.**

### Cómo probar el Bloque 8

1. Como chofer (`pedro@`, jornada en curso): toca una parada → "Entregada", elige "Enviar por email",
   pon un email, firma con el dedo, "Guardar".
2. El contenedor `queue` procesa el job: el PDF aparece en `storage/app/private/r2/albaranes/` y el
   correo (con PDF adjunto) en **Mailpit → http://localhost:8026**.
3. Como `admin@`: **Albaranes** en el nav. Filtra por estado/canal, descarga un PDF, prueba
   "Reprocesar" sobre uno en Error y "Marcar entregado" sobre uno físico.

---

## Bloque 9 — lo que se ha construido

**Gestión de clientes** (`clients`), módulo **independiente** (decisión del usuario): no se enlaza
con `route_stops` por FK; el histórico por cliente se empareja por `customer_tax_id` (CIF) o, si no
hay CIF, por `customer_name` exacto.

- **Tabla `clients`** (`2026_09_07_100000_create_clients_table`): `external_ref` (código Access,
  único, para el upsert del import), identificación (`name`, `tax_id`, `client_type`), contacto
  (`contact_name`, `phone`, `secondary_phone`, `email`), ubicación (`address`, `postal_code`,
  `city`, `province`, `latitude`/`longitude`), reparto habitual (`default_delivery_type_id`,
  `typical_quantity`, `frequency_days` — null = bajo demanda, `tank_capacity_liters`,
  `requires_own_pump`, `preferred_channel`, `price_per_liter`, `payment_terms`, `last_served_on`),
  observaciones (`access_notes`, `notes`), `is_active`, soft-deletes.
- **`App\Enums\ClientType`**: Particular, Empresa, Comunidad, Agrícola, Industrial, Obra, Otro.
- **`App\Models\Client`**: `Auditable` + `SoftDeletes`. `scopeSearch`, `pastStops()` (histórico
  emparejado por CIF/nombre), `nextDeliveryOn()` = `last_served_on` + `frequency_days`,
  `isDeliveryDue()`, `frequencyLabel()` (Semanal / Quincenal / Mensual / Cada N días).
- **Permisos** `clients.{view,create,update,delete}` en `RolePermissionSeeder::PERMISSIONS`
  (administrador y mantenimiento). `ClientPolicy` auto-descubierta. Un chofer recibe 403.
- **`/clientes`** (`App\Livewire\Clients\Index`, `clients.index`): listado con búsqueda + filtros
  (estado, tipo, "le toca reparto") + orden + paginación; badge ámbar "toca reparto"; crear/editar
  en modal, borrar con `wire:confirm`. Validación con `App\Livewire\Forms\ClientForm` (Form object,
  no FormRequest — coherente con el resto del panel).
- **`/clientes/{id}`** (`App\Livewire\Clients\Show`, `clients.show`): ficha propia con KPIs (repartos,
  litros servidos, último, próximo estimado), tarjetas de contacto/ubicación (enlace a Google Maps)
  y reparto habitual, "Cambios recientes" (auditoría del propio cliente), histórico de repartos a
  ancho completo (fecha / tipo / previsto / entregado / estado / chofer / albarán con enlace al PDF),
  y **"Planificar reparto"**: crea una parada en el backlog ("Sin asignar") del Kanban con los datos
  del cliente (requiere permiso `routes.update`).
- **Import desde Access** (una sola vez, por comando — decisión del usuario):
  `php artisan clientes:importar <archivo.csv> [--dry-run]`. El archivo se coloca en la raíz del
  proyecto (o ruta absoluta). `App\Services\ClientImporter` + `league/csv`:
  - Detecta el delimitador (`;` o `,`), normaliza las cabeceras y las mapea con ~60 alias en
    español/inglés (`codigo`→`external_ref`, `cif`/`nif`→`tax_id`, `poblacion`/`localidad`→`city`,
    `periodicidad`→`frequency_days` con `semanal`/`quincenal`/`mensual`, `litros`→`typical_quantity`,
    `observaciones acceso`→`access_notes`, etc.).
  - Números en formato español (`1.234,56`), tipo de reparto resuelto por slug/nombre.
  - **Upsert por `external_ref`** (usa `withTrashed()` y restaura si estaba borrado). Filas sin
    nombre o inválidas (email, coordenadas…) se saltan y se listan como error, sin abortar.
  - Plantilla de ejemplo: `docs/plantilla-clientes.csv`.
- **`Audits::MODELS`** incluye "Cliente"; enlace "Clientes" en el nav (`@can('viewAny', Client)`).
- **Seeder**: `DatabaseSeeder::seedClients()` crea ~57 clientes y reasigna ~75% de las paradas del
  historial a esos clientes (por `customer_name`/`customer_tax_id`) para que las fichas tengan
  histórico real. Guarda de idempotencia (`if (Client::query()->exists()) return`).
- Tests: `ClientsCrudTest` (5), `ClientShowTest` (3), `ClientImporterTest` (4). **Suite: 129 passed.**

### Cómo probar el Bloque 9

```bash
cd server && docker compose up -d && npm run build
docker compose exec laravel.test php artisan migrate:fresh --seed
```

1. Entra como `admin@servalillo.test` / `password` → "Clientes" en el nav (entre Rutas y Chofers).
2. Listado: busca por nombre/CIF/población, filtra por tipo y por "Le toca reparto", ordena columnas.
3. Crea un cliente (modal), edítalo, bórralo. Repite un código externo → error inline.
4. Abre una ficha (`/clientes/{id}`): KPIs, histórico de repartos con enlaces a los albaranes PDF,
   "Cambios recientes". Pulsa "Planificar reparto" → aparece una parada nueva en "Sin asignar" del
   tablero de Rutas.
5. Import: copia `docs/plantilla-clientes.csv` a la raíz del proyecto y ejecuta
   `docker compose exec laravel.test php artisan clientes:importar plantilla-clientes.csv --dry-run`
   (simulación) y luego sin `--dry-run`.
6. `soporte@servalillo.test` (mantenimiento) también entra; un chofer recibe 403.
7. `docker compose exec laravel.test php artisan test` → 129 passed.

### Formato del CSV de import

- Cabecera en la primera fila; delimitador `;` o `,` (autodetectado).
- Columna que identifica al cliente para el upsert: `Codigo` / `Code` / `Id` → `external_ref`.
- Alias reconocidos (no distingue mayúsculas/acentos): `Nombre`/`Razon social`, `CIF`/`NIF`,
  `Tipo`, `Persona de contacto`, `Telefono`/`Movil`, `Telefono 2`, `Email`, `Direccion`, `CP`,
  `Poblacion`/`Localidad`/`Municipio`, `Provincia`, `Latitud`/`Lat`, `Longitud`/`Lng`,
  `Tipo de reparto`/`Producto` (por nombre o slug), `Litros`/`Consumo`, `Periodicidad`/`Frecuencia`
  (`semanal`=7, `quincenal`=15, `mensual`=30, o un número de días), `Capacidad`/`Deposito`, `Bomba`,
  `Canal` (`email`/`fisico`), `Precio`, `Forma de pago`, `Ultimo reparto` (fecha), `Acceso`,
  `Observaciones`, `Activo` (`si`/`no`).

### Añadido: tipo de servicio Reparto / Viajes (`App\Enums\ServiceKind`)

Hoy solo se opera **Reparto**; **Viajes** se gestionará más adelante, pero la clasificación ya existe:
- Columna `service_kind` (string, default `'reparto'`) en `clients`, `routes` y `route_stops`
  (migración `2026_09_07_110000_...`), casteada al enum en los tres modelos.
- **Clientes**: selector en el alta/edición + filtro en `/clientes` + badge "Viaje".
- **Tablero de rutas** (`/rutas`): segmentado **"Repartos | Viajes"** (`#[Url] $kind`, Reparto por
  defecto) que filtra columnas y backlog "Sin asignar". Una parada creada desde el tablero hereda el
  tipo del filtro activo; una "planificada" desde la ficha del cliente hereda el del cliente.
- **Ficha de ruta** (`/rutas/listado`): selector "Tipo de servicio" en el formulario + badge.
- Detalle en `CLAUDE.md` (sección "Tipo de servicio: Reparto / Viajes").

### Añadido: Pre-clientes ("Pendiente valoración", `App\Enums\ClientStatus`)

Administración apunta por teléfono un posible cliente (nombre, teléfono, dirección + coordenadas,
tipo de agua, litros/m³, distancia depósito↔camión, observaciones) → queda como **"Pendiente
valoración"** → tras consultar con los responsables: **Aprobar** (pasa a cliente real y abre el
modal de edición para completar) o **Descartar** (borrado permanente, `forceDelete`).
- Migración `2026_09_07_130000_...` añade `clients.status` (default `'customer'`), `water_type`,
  `quantity_unit`, `tank_distance_m`.
- Alta en el **mismo modal "Nuevo cliente"** con un toggle "Cliente | Pendiente valoración" que
  colapsa el formulario a los campos de la llamada.
- `/clientes` oculta los prospectos salvo el filtro Estado → "Pendiente valoración". Botones
  Aprobar/Descartar por fila y en la ficha. Sin permiso nuevo (reusa `clients.update`/`clients.delete`).
- La **cantidad habitual** pasa a guardarse siempre en litros para todos los clientes; `quantity_unit`
  recuerda la unidad citada (la ficha muestra "3 m³ (3.000 L)").
- El **precio** puede ser tarifa fija o por litro (`clients.price` + `price_type`, enum `PriceType`;
  migración `2026_09_07_140000_...` renombra `price_per_liter` → `price`).
- **Calendario de reparto** (migración `2026_09_07_150000_...`): además de "cada N días", el cliente
  puede tener **días fijos de la semana** (`delivery_weekdays`) con **rango de fechas opcional**
  (`schedule_starts_on`/`schedule_ends_on`). Los clientes con días fijos **generan su parada
  automáticamente** en el tablero para el día que toca (`App\Services\RecurringStopService`,
  `route_stops.scheduled_for`; comando `rutas:generar-recurrentes`, scheduler diario). El producto
  siempre es agua → se eliminó `clients.default_delivery_type_id`.
- **El chofer reprograma paradas**: al marcar una parada **Fallida** o **Cancelada** puede elegir una
  fecha futura; se cierra la actual y nace una parada pendiente para ese día (en su ruta de ese día
  si tiene, o en "Sin asignar"). El chofer la ve ese día en "Reprogramadas para este día".
- **Sincronización automática admin ↔ chofer** vía `wire:poll` (chofer 15 s, tablero 45 s): lo que
  cambia uno le aparece al otro solo, sin recargar. (Push instantáneo con Reverb = mejora futura.)
- Detalle en `CLAUDE.md` (sección "Pre-clientes / valoración" y la de clientes).

---

## Bloque 10 — lo que se ha construido

**API de ingesta GPS** para una APK "tracker" sin interfaz (ver el re-alcance arriba). La operativa
del chofer sigue siendo la web Livewire.

- **`POST /api/device/register`** (sin auth, `throttle:device-register` 10/min por IP): la APK envía
  `install_identifier` + el secreto compartido (`DEVICE_ENROLMENT_SECRET`, `config('servalillo.device')`).
  Se registra el `Device` "en blanco" (sin chofer), se emite un token Sanctum en el propio `Device`
  con habilidad `gps:ingest` y se devuelve `{ token, device_id, tracking: {intervalo, distancia, pausa} }`.
- **`POST /api/gps/batch`** (`auth:sanctum` + `abilities:gps:ingest`): lote de hasta 500 posiciones.
  `App\Services\GpsIngestService` descarta las de fecha futura, resuelve chofer (del device) y
  camión/ruta (de la ruta de ese chofer para la fecha de la posición — `null` si no procede) e inserta
  en bloque. Bumpea `last_seen_at`.
- **`GET /api/device`** (`auth:sanctum` + `abilities:gps:ingest`, añadido en el Bloque 11): estado del
  dispositivo para la pantalla de la APK → `{ device_id, label, is_active, driver: {name}|null,
  tracking, server_time }`. **No aborta** si el dispositivo está desactivado: devuelve 200 con
  `is_active: false` para que la APK pare con elegancia.
- **Esquema**: `devices.truck_id` → `devices.driver_id` (nullable, unique). `gps_positions.truck_id`
  pasa a nullable.
- **Panel `/mantenimiento/dispositivos`** (`App\Livewire\Maintenance\Devices`, permiso `devices.manage`):
  lista de APKs enroladas (última señal, versión, nº posiciones, estado) + asignar/reasignar chofer,
  revocar acceso, activar/desactivar, eliminar (con sus posiciones).
- **`gps:purgar {--dias=}`** + schedule diario 04:00: retención `gps_retention_days` (90).
- Los errores de la API se registran solos en `error_logs` (panel de Mantenimiento) — el
  `bootstrap/app.php` ya lo hacía para `api/*`.

### Cómo probar el Bloque 10
1. `.env`: poner `DEVICE_ENROLMENT_SECRET` a algo. `php artisan config:clear`.
2. `curl -sX POST localhost:8000/api/device/register -H 'Accept: application/json'
   -d 'install_identifier=phone-1&secret=<secreto>&platform=android&app_version=1.0.0'` → token.
3. `curl -sX POST localhost:8000/api/gps/batch -H "Authorization: Bearer <token>" -H 'Accept: application/json'
   -H 'Content-Type: application/json' -d '{"positions":[{"lat":28.46,"lng":-16.25,"recorded_at":"2026-09-08T10:00:00Z","battery_level":88}]}'` → `{"accepted":1}`.
4. `soporte@servalillo.test` → Mantenimiento → **Dispositivos** → asignar el dispositivo a un chofer →
   volver a enviar un lote → la posición ya trae `driver_id`/`truck_id`/`route_id`.
5. `php artisan gps:purgar` (sin datos viejos no borra nada).

### Tests
`tests/Feature/Api/{DeviceRegisterTest (5), GpsBatchTest (11), DeviceShowTest (7)}.php`,
`PurgeGpsPositionsTest (2)`, `MaintenanceDevicesTest (9)`. **Suite total: 260 tests en verde.**

---

## Bloque 11 — lo que se ha construido

**APK Android "tracker" sin interfaz** (`mobile/`, Flutter). El servicio técnico la instala en el
móvil de empresa de cada camión; se abre una vez para conceder permisos y a partir de ahí comparte la
ubicación en segundo plano para siempre. **Sin login, sin interacción del chofer.**

- **Servicio en primer plano** (`flutter_foreground_task`): muestrea la posición cada
  `ping_interval_seconds` (config del servidor), la encola en SQLite y la envía en lotes de ≤500 a
  `POST /api/gps/batch`. Sobrevive a cerrar la app y se revive al reiniciar el móvil
  (`RECEIVE_BOOT_COMPLETED`, solo con "ubicación siempre" concedida).
- **Cola offline** (`sqflite`): si no hay red, la cola crece (tope ~50 000 filas ≈ 26 días) y se vacía
  al volver. Borra el lote entero ante cualquier 2xx (anti-duplicado por id contiguo).
- **Respeta la ventana de pausa** (`pause_start`–`pause_end`, def. 22:00–05:00): en ese rango el
  servicio sigue vivo pero no muestrea ni envía.
- **Enrolamiento**: al primer arranque envía un `install_identifier` (UUID persistente) + el
  `ENROLMENT_SECRET` (horneado en el build con `--dart-define`) a `POST /api/device/register` y guarda
  el token Sanctum en `flutter_secure_storage`. 401 → "re-enrolar"; 403 → "desactivado".
- **Una pantalla de estado**: botón de permisos (wizard secuencial) + estado (servicio, enrolado,
  chofer asignado vía `GET /api/device`, última señal, última posición enviada, pendientes en cola).
- Config del servidor: `SERVER_URL` (default = dev tunnel), `ENROLMENT_SECRET` (sin default),
  `APP_VERSION`. En `mobile/dart_define.json` (gitignored); plantilla en `dart_define.example.json`.
- Toolchain para compilar: JDK 17 + Android SDK (cmdline-tools) — ver `mobile/README.md`.
- **Tests Dart** (`mobile/test/`, 53): `pause_window`, `tracked_position`, `tracking_config`,
  `backoff`, `tracker_api` (MockClient), `position_queue` (`sqflite_common_ffi`), `sync_service`,
  `enrolment_service`, `tracker_controller`.

### Cómo probar el Bloque 11
1. Servidor accesible desde el móvil (dev tunnel de VSC o LAN). `.env` con `DEVICE_ENROLMENT_SECRET` y
   `APP_URL`/`trustProxies` OK. `php artisan config:clear`.
2. `cd mobile` → copiar `dart_define.example.json` a `dart_define.json` y poner `SERVER_URL` +
   `ENROLMENT_SECRET` (= `DEVICE_ENROLMENT_SECRET` del servidor).
3. `flutter build apk --release --dart-define-from-file=dart_define.json` →
   `mobile/build/app/outputs/flutter-apk/app-release.apk`.
4. `adb install -r app-release.apk` (o pasar el APK al móvil e instalar). Abrir → conceder los 4
   permisos (ubicación, ubicación "todo el tiempo" en Ajustes, notificaciones, batería sin restricción).
5. `soporte@servalillo.test` → Mantenimiento → **Dispositivos**: aparece enrolado → asignar a un
   chofer → los siguientes lotes traen `driver_id`/`truck_id`/`route_id`.
6. Escenarios: cerrar la app (swipe) → sigue enviando; modo avión 10 min → la cola crece y se vacía al
   volver; revocar el token en el panel → la APK muestra "re-enrolar"; desactivar el dispositivo →
   para y muestra "desactivado"; dentro de 22:00–05:00 → deja de muestrear.

### Sobre el tracker (encima del Bloque 11)
- **"Localizar" en el panel de Dispositivos**: mapa Leaflet con la última posición + rastro de las
  ≤60 recientes.
- **"Ruta eficiente" desde la posición real del camión**: el modal ofrece "Ubicación actual del
  camión" como punto de salida cuando el tracker tiene señal reciente
  (`RouteOptimizer::latestVehiclePosition`).
- **"Ver recorrido"** dibuja el camión (🚚) y el trazado por carretera hasta la primera parada
  pendiente (`RouteGeometry` payload `vehicle.approach`).
- **Navegar con Google Maps** (`App\Support\GoogleMaps`): en `/chofer/ruta`, botón "Seguir ruta en
  Google Maps" (paradas pendientes como waypoints, sin origin → GPS del móvil), icono de navegación
  por parada, "Cómo llegar" en el modal de parada, y "Abrir en Google Maps" en el modal "Ver
  recorrido" (chofer + oficina). Sin API key. Probar como `pedro@servalillo.test` en el móvil: el
  enlace abre la app de Google Maps.

---

## Bloque 12 — lo que se ha construido

**Notificaciones in-app + canal de soporte administración ↔ mantenimiento.** Comparten la campana
del nav.

### Notificaciones
- Tabla `notifications` **nativa** de Laravel (migración `2026_09_08_000001_...`; `data` como `jsonb`,
  PK `uuid` sin tocar). Canal **`database`** únicamente, envío **síncrono** (sin `ShouldQueue`).
- 4 clases en `app/Notifications/`: `ChoferRouteChanged`, `SupportTicketOpened`,
  `SupportTicketReplied`, `SupportTicketStatusChanged`. Esquema `toArray()` común
  (`type/title/body/url/icon`) para que la campana pinte cualquiera.
- **Campana** = `App\Livewire\Notifications\Bell` anidada en el nav Volt, `wire:poll.30s`, montada
  para `isManager()`. Contador de no leídas + desplegable; al pulsar una notificación se marca leída
  y navega a su `url`.
- **Disparadores del chofer** (solo desviaciones del plan, vía `App\Support\Notifications\
  RouteChangeNotifier` — dispatch explícito, no observers): parada **fallida**/**cancelada**,
  **reprogramada** a otro día, **cliente añadido** sobre la marcha, **jornada cerrada con descuadre**
  de litros. Entrega normal y empezar/terminar jornada sin incidencia → no notifican. Destinatario:
  los **administradores**.

### Canal de soporte
- `support_tickets` (asunto + categoría `SupportCategory` + estado `SupportStatus` + primer mensaje,
  softDeletes) + `support_ticket_replies` (migraciones `2026_09_08_000002/3_...`). No auditado.
- Permisos `support.create` (admin + mantenimiento) y `support.manage` (solo mantenimiento).
  `SupportTicketPolicy`: el creador edita/borra su mensaje **solo mientras el otro lado no responde**.
- **Admin**: botón llave inglesa en el nav → `/soporte` (`App\Livewire\Support\Index`) — sus
  incidencias, alta, hilo, responder, editar/borrar lo propio.
- **Mantenimiento**: 4ª pestaña de `/mantenimiento` → `/mantenimiento/soporte`
  (`App\Livewire\Maintenance\Support`) — todas las incidencias, filtros (estado/categoría/búsqueda/
  fechas), hilo en modal, cambiar estado, borrar.
- Notificaciones (`App\Support\Notifications\SupportNotifier`): alta → mantenimiento; respuesta → el
  otro lado; cambio de estado → el creador.

### Panel de estadísticas del rol mantenimiento
- `/home` redirige a `mantenimiento` a **`/mantenimiento`** (antes iba a `/dashboard`). Nueva pestaña
  **"Resumen"** (`App\Livewire\Maintenance\Overview`, sustituye al viejo `Route::redirect`) — un
  `/dashboard` dedicado al técnico, con `App\Services\MaintenanceStatsService`:
  - Selector de rango 7/30/90 días.
  - KPIs: incidencias abiertas (y sin responder), resueltas del periodo, errores del periodo y de las
    últimas 24 h, cambios auditados, notificaciones sin leer, peso del log.
  - 4 gráficos Chart.js (`Alpine.data('maintenanceCharts')`, mismo patrón que el panel de la empresa):
    errores por día, incidencias abiertas vs resueltas, actividad de auditoría por día, incidencias
    por categoría.
  - Barras "excepciones más frecuentes" y "modelos más modificados".
  - **Avisos accionables** (`alerts()`, sin rango): incidencias sin respuesta > 2 días, errores en
    24 h, log > 5 MB, errores de > 30 días sin purgar.
  - Tarjetas con las últimas incidencias / errores / auditorías / ficheros de log.
- El panel estadístico de la empresa (`/dashboard`) sigue accesible desde el nav.

### Tests
`NotificationBellTest`, `ChoferRouteNotificationsTest`, `SupportChannelAdminTest`,
`SupportChannelMaintenanceTest`, `MaintenanceOverviewTest` (~33 casos nuevos). **Suite total: 188
tests en verde.**

Detalle en `CLAUDE.md` (sección "Notificaciones y canal de soporte (Bloque 12)").

### Cómo probar el Bloque 12
1. `admin@servalillo.test` → la campana muestra un contador; abrir → notificaciones de ejemplo; pulsar
   una → marca leída y navega al tablero/incidencia.
2. Botón llave inglesa → `/soporte` → "Nueva incidencia" → enviar. Abrir el hilo, responder.
3. `soporte@servalillo.test` → aterriza en **`/mantenimiento`** (pestaña "Resumen" con KPIs +
   incidencias/errores/auditoría/logs) → campana con "Nueva incidencia de soporte" → pestaña
   **Soporte** → ver, filtrar, responder, cambiar estado.
4. El admin recibe "Respuesta en una incidencia" y "Estado de incidencia actualizado"; ya no puede
   editar su primer mensaje.
5. `pedro@servalillo.test` → `/chofer/ruta` → marcar una parada **Fallida** / reprogramarla / "Añadir
   cliente (ha llamado)" / cerrar jornada con lectura descuadrada. El admin recibe una notificación
   por cada acción (no por una entrega normal).
6. `/soporte` como chofer → 403; `/mantenimiento/soporte` como admin → 403.

---

## Bloque 13 — lo que se ha construido

**Botón "Ruta eficiente"** que reordena automáticamente las paradas pendientes de una ruta para
acortar el recorrido. Dos entradas: cabecera de cada columna del tablero (`/rutas`) y bajo la lista
de paradas del chofer (`/chofer/ruta`, "Organizar mi ruta"). Se intercaló por delante de 10/11.

- **`App\Services\RouteOptimizer`** (`optimize(RouteDay)` + `toast(array)`): pide a **OSRM `/table`** la
  matriz de distancias reales por carretera y resuelve el camino abierto con **vecino más cercano +
  2-opt** sobre esa matriz (`config('servalillo.routing')`, `OSRM_URL` autoalojable, demo público sin
  API key). **Fallback obligatorio** al mismo algoritmo sobre distancia en línea recta
  (`App\Support\Haversine`) si OSRM falla. **Nunca deja la ruta peor** que como estaba (compara con
  el orden actual). (NO se usa `/trip` con `roundtrip=true`: optimiza un circuito, no un camino.)
- **Punto de partida:** al pulsar el botón, un modal (`<x-route-optimize-modal>`, compartido tablero +
  chofer) pregunta **"¿Desde dónde sale el camión?"** → **"Desde la base"** (`config('servalillo.base')`,
  `BASE_LATITUDE`/`BASE_LONGITUDE`) o **"Desde un cliente"** (lista de las paradas pendientes con
  ubicación de la ruta). Ese punto se ancla como primera parada y el resto se optimiza desde ahí. Si
  se llama sin origen (directo / tests) se toma la última parada cerrada, o libre si no hay ninguna.
- **Chofer — "Ir a la base a repostar"**: botón aparte (`Today::optimizeFromBase()`, `wire:confirm`)
  para cuando el camión tiene que volver a la nave a rellenar: un toque reordena las pendientes
  saliendo de la base, sin modal; las completadas no se mueven.
- Solo reordena `Pending`; las **cerradas quedan FIJAS en su hueco** (relativo al flujo de pendientes;
  su `position` solo se renumera al compactar). Las pendientes sin coordenadas se anexan al final.
  Persiste `position` en transacción, solo filas que cambian.
- **`Board::reorderStops`** (arrastre en el tablero): las paradas **cerradas ya no se pueden mover** —
  aunque SortableJS las desplace al soltar otra tarjeta cerca, `reindexColumn()` las devuelve a su
  hueco y recoloca las pendientes alrededor (mismo criterio que `RouteOptimizer`). El 422 se reserva
  para intentar reasignar de ruta una parada cerrada.
- Permiso nuevo `routes.optimize.own` (chofer + admin + mantenimiento); `RouteDayPolicy::optimizeOwn`
  (chofer, con propiedad) y `RouteDayPolicy::reorderStops` (oficina, cableado desde el tablero).
- Primer uso del `Http` facade. `phpunit.xml` fija `ROUTING_OSRM_ENABLED=false` (tests deterministas
  con la heurística local); los tests de OSRM hacen `Http::fake()`.

**"Ver recorrido"** (mismo bloque): botón junto a "Ruta eficiente" (tablero + chofer, arriba) que
abre un **mapa Leaflet** (OpenStreetMap, sin API key) con las paradas numeradas y el **trazado real
por carretera** (OSRM `/route`, con distancia y duración; cae a línea recta si falla).
`App\Services\RouteGeometry` + evento `open-route-map` + `Alpine.data('routeMap')` +
`<x-route-map-modal>` compartido. El botón "Organizar mi ruta" del chofer pasa a la parte superior.

### Tests
`RouteOptimizerTest` (12), `RouteGeometryTest` (4) + añadidos a `RoutesBoardTest`, `ChoferTodayTest`,
`RolesAndPoliciesTest`. **Suite total: 223 tests en verde.**

Detalle en `CLAUDE.md` (sección "Ruta eficiente (Bloque 13)").

### Cómo probar el Bloque 13
1. `admin@servalillo.test` → `/rutas` con una ruta de varias paradas → "Ruta eficiente" en la
   cabecera de la columna → modal "¿Desde dónde sale el camión?" → "Desde la base" → toast "Ruta
   reordenada: N paradas · ~X km menos"; cambia el orden de las tarjetas. Pulsar otra vez → "ya
   estaba en el orden más eficiente".
2. "Ruta eficiente" → "Desde un cliente" → elegir una parada → esa parada queda la primera y el
   resto se ordena desde ella.
3. `OSRM_URL=http://127.0.0.1:1` (basura) + `php artisan config:clear` → "Ruta eficiente" → toast
   "(Estimación local: el servicio de rutas no respondió.)"; sigue reordenando.
4. Parada creada desde "+ Añadir parada" (sin coords) → tras optimizar queda al final; el toast lo dice.
5. En una columna con una parada **completada**, arrastrar una pendiente por encima o por debajo → la
   completada vuelve a su sitio (no se puede mover).
6. `pedro@servalillo.test` → "Organizar mi ruta" (arriba) → mismo modal (base / cliente) → se
   reordenan las pendientes por el camino más corto. "Ir a la base a repostar" hace lo mismo desde
   la base de un toque (sin modal); las paradas ya completadas no se mueven.
7. "Ver recorrido" (chofer o cualquier columna del tablero) → mapa con las paradas numeradas y el
   trazado por carretera + "~X km · ~Y min". `OSRM_URL` basura → cae a línea recta.
8. `/rutas` como chofer → 403 (ya lo era); el chofer no tiene el botón "Ruta eficiente" del tablero.

---

## Bloque 14 — lo que se ha construido

**Diario de incidencias del chofer** (`/chofers/{driver}/diario`, `App\Livewire\Drivers\Diary`,
enlazado con un botón "Diario" desde cada fila de `/chofers`). Notas internas de oficina sobre lo
que hace un chofer, bueno o malo — no las escribe el chofer.

- **`App\Models\DriverLog`** (`occurred_on`, `category`, `body`, `created_by`, `updated_by`
  nullable, `SoftDeletes`, `Auditable`). `App\Enums\DriverLogCategory` (positiva/negativa/neutra,
  con `->badgeVariant()`) para distinguir de un vistazo lo bueno de lo malo en el listado.
- **`updated_by`** se queda `null` hasta que alguien edita la nota (nunca al crearla); el listado
  muestra "Anotado por X el…" y, si se editó, "Editado por Y el…" + **"Ver cambios"** (modal con un
  historial curado — solo `occurred_on`/`category`/`body`, nunca `id`/timestamps/autores en crudo —
  a diferencia del `Audit::getModified()` genérico de `/mantenimiento/auditoria`, que sigue viendo
  el mismo cambio sin filtrar).
- Permisos nuevos `driver_logs.{view,create,update,delete}` (administrador + mantenimiento, no
  chofer). Filtros: categoría, rango de fechas, búsqueda en el texto.
- Detalle completo (incl. dos gotchas reales: un enum sin `__toString()` reventaba el modal de
  cambios, y por qué el historial filtra en vez de usar `getModified()` en crudo) en `CLAUDE.md`
  → "Diario de incidencias del chofer".

### Cómo probar el Bloque 14
1. `admin@servalillo.test` → **Chofers** → botón "Diario" en una fila → "Nueva anotación".
2. Edítala → aparece "Editado por…" + "Ver cambios" con el histórico legible.
3. Filtra por categoría (positiva/negativa/neutra) y por texto.
4. El mismo cambio aparece también en `/mantenimiento/auditoria` (evento "Actualizado", modelo
   "Incidencia de chofer") — sin filtrar, para comparar con la versión curada del diario.

---

## Bloque 15 — lo que se ha construido

**Tiempo de permanencia en cada parada**, calculado a partir del GPS y una geocerca (radio
configurable). Responde "cuánto tiempo estuvo el camión parado en cada cliente" sin tocar la
ingesta GPS: es un cálculo por **"replay"** que se puede recalcular tantas veces como haga falta.

- **`App\Services\StopDwellService::recomputeForRouteDay(RouteDay)`** reprocesa el track GPS
  completo del día y reconstruye `stop_visits` (borra + reinserta por día → idempotente, aguanta
  lotes de GPS desordenados o de recuperación tras un corte de red).
- **Reglas** en `config('servalillo.dwell')` (radio, mínimo para contar como parada, hueco máximo
  que se puentea sin cortar la visita, rechazo de fixes con mala precisión, exclusión de la zona de
  la base, recorte al horario de jornada).
- **Recálculo**: comando `paradas:calcular-permanencia` (scheduler diario 03:30) + recálculo
  perezoso al abrir el tablero/la web del chofer/el Historial (con sello + lock para no repetir
  trabajo en cada poll).
- **UI**: `<x-stop-dwell>` (badge "⏱ 14 min" / "● En parada 6 min" en las tarjetas; línea "Llegada
  10:32 · Salida 10:49 · 17 min" en los modales), velocidad actual del camión y tiempo/velocidad
  **entre** dos paradas consecutivas (`StopDwellService::transitLegs()`) en el Historial.
- **Dos bugs reales corregidos** durante las pruebas de campo, ambos con moraleja para el futuro:
  un umbral de corte de hueco GPS que nunca dejaba actuar al umbral pensado para ser el real
  (unificados en uno solo), y un cambio de comportamiento de **Carbon 3** (`diffInSeconds()` ya no
  es absoluto por defecto) que, con los argumentos en el orden "intuitivo" de Carbon 2, daba
  duraciones negativas y **ninguna visita se guardaba nunca** — detalle completo, incl. cómo se
  detectó, en `CLAUDE.md` → "Tiempo de permanencia en parada".

### Cómo probar el Bloque 15
1. Con una ruta que tenga GPS real o simulado: `php artisan paradas:calcular-permanencia` (o
   espera al recálculo perezoso al abrir el tablero/la web del chofer).
2. Tarjetas del tablero y de `/chofer/ruta`: badge de tiempo en parada; si el camión sigue dentro
   de la geocerca, badge ámbar "En parada X min" en vivo.
3. `/rutas/{route}/historial` → "Ver detalle" de un día: línea de llegada/salida por parada + tramo
   "🚚 En ruta X min · Y km/h de media" entre paradas consecutivas.
4. Ficha de un cliente (`/clientes/{id}`): estadística "Media en parada".

---

## Bloque 16 — lo que se ha construido

**Terminal vinculado a una ruta** (chofer sustituto): si el chofer titular de una ruta se pone
enfermo, un sustituto solo tiene que iniciar sesión desde el teléfono del camión (ya "vinculado" a
esa ruta) para pasar **directamente** a gestionarla ese día con su propio nombre — sin que oficina
reasigne nada a mano en el caso normal.

- **`route_terminals`** (token opaco `Str::random(48)` en una cookie de por vida, revocación
  blanda). Vincular/revocar desde `/rutas/listado` (botón "Terminales" por fila, gateado por el
  mismo permiso `routes.update` — sin permiso ni policy nuevos).
- **`GET /terminal/vincular/{token}`** (sin middleware `auth`, como `theme.update`): sella la
  cookie y redirige a login (o directo si ya hay sesión).
- **`App\Services\RouteTerminalPairingService::resolveDriverHome()`**: al entrar como chofer desde
  un terminal vinculado, si el día de hoy de esa ruta no es ya suyo, lo reasigna (auditado
  automáticamente, `RouteDay` ya es `Auditable`) — incluido el dispositivo GPS tracker del chofer
  original, si el sustituto no tiene ya el suyo propio.
- **Guarda de colisión**: si el sustituto ya tiene su propia ruta asignada hoy, no se sustituye
  nada — evita que un chofer acabe con dos `RouteDay` el mismo día, algo que rompería varias
  asunciones del Bloque 15/10 (fallback de GPS por `driver_id`+fecha, mapa "dónde está el camión").
- **Se autocorrige solo al día siguiente** (la generación diaria de `RouteDay` parte siempre del
  `driver_id` de la ruta permanente, nunca del de un `RouteDay` ya existente) — sin código de
  "revertir".
- **Válvula manual** en `/rutas/{route}/historial` ("Reasignar chofer") para deshacer una
  sustitución equivocada o forzar una que la guarda de colisión bloqueó.
- Un bug real (`redirect('chofer.today')` en vez de `redirect(route('chofer.today'))`, que habría
  roto el login de **todos** los chofers) se detectó y corrigió antes de desplegar, gracias a los
  tests. Detalle completo en `CLAUDE.md` → "Terminal vinculado a una ruta".

### Cómo probar el Bloque 16
1. `admin@servalillo.test` → `/rutas/listado` → "Terminales" en una fila → "Vincular un terminal
   nuevo" → copia el enlace.
2. Ábrelo en una ventana de incógnito → inicia sesión como un chofer **distinto** al titular de esa
   ruta → debe aterrizar directo en `/chofer/ruta` viendo esa ruta con su propio nombre.
3. `/mantenimiento/dispositivos`: el GPS tracker del camión ya aparece a nombre del sustituto.
4. Al día siguiente (o simulando la fecha), la ruta vuelve a generarse con el chofer titular.
5. `/rutas/{route}/historial` → "Reasignar chofer" para devolverla a mano si hiciera falta.

---

## Bloque 17 — lo que se ha construido

**Depósitos SGRA**: nivel de agua en vivo de un proyecto totalmente aparte del usuario (SGRA —
Sistema de Gestión de Recursos del Aljibe, monitorización de aljibes con sensores + LoRa/4G en su
Raspberry Pi de casa), visible desde el propio panel de Gestión Servalillo para no tener que salir
a un dashboard distinto al planificar repartos.

- **`App\Services\SgraClient`**: único punto que habla con la API externa. Login por sesión/cookie
  (la API de SGRA no tiene token/API key), selecciona cada depósito y lee su nivel actual — nunca
  lanza al llamador, cualquier fallo (red, timeout, login rechazado) se traga y devuelve `[]` para
  que el panel se degrade con elegancia si el NAS está apagado.
- **`/depositos`** (`App\Livewire\Sgra\Index`, permiso `sgra.view`, administrador + mantenimiento):
  una tarjeta por depósito con **visual 3D animado** (puerto directo a Three.js/Alpine del
  componente Vue que ya usa el propio dashboard SGRA — depósito, agua, flotador, persona de
  referencia), nivel %, altura de agua y estado ("En línea"/"Sin datos recientes"/"Nivel bajo"), con
  un botón "Ver panel completo" al dashboard SGRA real.
- **Conectividad = Tailscale** (decisión explícita del usuario, no la URL pública sin cifrar): el
  VPS de producción se unió al mismo tailnet que el NAS del usuario.
- Caché de 30s (`Cache::remember`) para no repetir login + N llamadas en cada `wire:poll.60s` de
  cada pestaña abierta contra un Raspberry Pi doméstico.
- Detalle completo (incl. el gotcha de que el "depósito activo" de la API de SGRA es un concepto de
  sesión, no un parámetro) en `CLAUDE.md` → "Depósitos SGRA".

### Añadido en la misma sesión (fuera del alcance de este bloque, pulido general)
- **`/albaranes`**: botón **"Ver PDF"** (abre el PDF en una pestaña nueva, `Content-Disposition:
  inline`) junto al ya existente, renombrado a **"Descargar"** (`?view=1` en la misma ruta —
  `Storage::disk('r2')->response()` en vez de `->download()`).
- **`<x-ui.stat-card>`**: el tamaño del valor ahora se adapta a su longitud (un texto corto como
  "128" sigue grande; una fecha larga como "28/04/2026" se encoge) — antes se salía del borde de la
  tarjeta en rejillas de 5 columnas (ficha del cliente).

### Cómo probar el Bloque 17
1. `admin@servalillo.test` (o `soporte@`) → **Depósitos** en el nav → tarjetas con la animación 3D
   y el nivel real de cada aljibe.
2. "Ver panel completo" abre el dashboard SGRA de verdad en una pestaña nueva.
3. `/albaranes` → botones "Ver PDF" (pestaña nueva, sin descargar) y "Descargar" (como antes).
4. `/clientes/{id}` con histórico: la tarjeta "Último reparto" ya no toca el borde.

---

## Punto de continuación (última sesión: 2026-09-11)

**Estado:** app en producción en `https://geosafety.es`, desplegada varias veces esta sesión
(Bloque 16, Bloque 17, y los dos ajustes de pulido de arriba). El VPS de Hetzner se unió al
tailnet de Tailscale del usuario esta sesión, expresamente para el Bloque 17. Servidor: **409 tests
en verde** (`cd server && docker compose exec -T laravel.test php artisan test`).

**Bug real de producción corregido esta sesión (antes del Bloque 16):** `GpsIngestService` no
convertía `recorded_at` (que la APK manda en UTC) a la zona horaria de la app antes de guardarlo —
invisible en local (donde `APP_TIMEZONE` es UTC por defecto) pero en producción
(`APP_TIMEZONE=Europe/Madrid`) desplazaba cada posición 1-2 h, rompiendo silenciosamente el tiempo
de permanencia (Bloque 15) y "ubicación actual del camión" (Bloque 13). Backfill de los datos ya
guardados con `AT TIME ZONE`. Detalle en `CLAUDE.md` → "API de tracking GPS (Bloque 10)".

**Infra nueva de esta sesión:** además de Tailscale en el VPS, se dejaron dos pasos pendientes de
higiene que el usuario debe completar cuando tenga un momento: volver a bloquear la contraseña del
usuario `deploy` en el VPS (`sudo passwd -l deploy` — se le puso una contraseña temporal para poder
instalar Tailscale sin acceso root por SSH) y confirmar que la auth key de Tailscale usada durante
el alta quedó revocada.

**Bug real de producción corregido esta sesión, en dos intentos (mapas Leaflet):**
`tile.openstreetmap.org` (los dos mapas de la app, "Ver recorrido" y "Localizar dispositivo")
empezó a devolver 403 "Access blocked" en producción. Primer intento, **CARTO Voyager**, pareció
funcionar (200 OK) pero resultó estar igual de bloqueado: el "mapa" que se veía en realidad era una
imagen-aviso con "API KEY REQUIRED" superpuesto — visto por el usuario en el navegador, no
detectado antes porque solo se había comprobado el código HTTP, no el contenido real de un tile.
**Fix definitivo**: tiles REST públicos de **Esri (ArcGIS Online)** — verificado esta vez
descargando y mirando un tile real sobre Alicante antes de darlo por bueno. Además, el pie de
atribución (obligatorio, no se puede quitar del todo — es la condición de usar estos servicios
gratis) se dejó compacto ("Tiles © Esri", sin el prefijo "Leaflet |") a petición del usuario, que
ocupaba varias líneas en tarjetas pequeñas. Detalle completo en `CLAUDE.md` → "Ver recorrido" →
Gotchas Leaflet.

**Bug real de test corregido esta sesión (bloqueó un despliegue):** `TruckFactory` generaba el
código del camión en un rango de solo 1-99, mientras dos tests fijan a mano `code: 'C-99'`
(`FleetStatsServiceTest`, `TrucksCrudTest`) sin que `fake()->unique()` se entere de que ese valor
está reservado — un camión aleatorio creado de refilón (p. ej. al crear un `RouteDay` sin indicar
`route_id`, que internamente crea una `Route` + `Truck` nuevos) tenía ~1% de probabilidad de tocar
justo el 99 y violar `trucks_code_unique`. `deploy.sh` frenó el despliegue correctamente (nunca
llegó a tocar producción) al toparse con esto — así es como se detectó. Ampliado a 4 dígitos.

**Pendiente conocido, sin resolver esta sesión:** paginación de los listados Livewire en inglés
(ver Bloque 6) — transversal, pequeño, sigue sin hacerse.

---

## Punto de continuación (última sesión: 2026-09-10)

**Estado:** app **en producción** en `https://geosafety.es` (Hetzner CX23, aprovisionado y
desplegado esta sesión — detalle completo en `docs/04-despliegue-vps.md`). Servidor: **299 tests en
verde**. `develop`/`test`/`main` sincronizadas en el VPS tras cada cambio.

**Gotchas de producción encontrados y arreglados** (los tres en la tabla de Troubleshooting de
`docs/04`): PHP 8.3 no basta (Symfony 8 de Laravel 13 exige ≥8.4, VPS pasó a `ppa:ondrej/php`);
`opcache.validate_timestamps=0` exige `systemctl reload php8.4-fpm` tras cualquier cambio de código
que no pase por `deploy.sh`; `R2_ACCESS_KEY_ID=???` (placeholder no vacío) rompía firmas/PDFs al ser
*truthy*; permisos `0700` en directorios nuevos de `storage/app/private/r2` (Flysystem por defecto)
bloqueaban a `www-data` si los creaba `deploy` o viceversa — arreglado con `'permissions'` en
`config/filesystems.php`. SMTP real: Hostinger, puerto **587 + STARTTLS** (Hetzner bloquea 25/465
salientes por defecto en VPS nuevos).

**Bugs de negocio encontrados en la primera prueba de campo real y arreglados:**
- "Litros pedidos" (campo obligatorio del schema de agua, redundante con "Litros entregados") hacía
  fallar el guardado del albarán sin que el chofer entendiera por qué, y de rebote la firma se veía
  borrada en pantalla al re-renderizar. **Quitado** del `field_schema` de producción.
- Mapas Leaflet a veces se abrían mostrando toda la Península: `fitBounds()`/`setView()` se llamaban
  **antes** de `invalidateSize()`, así que usaban el tamaño de contenedor cacheado (de antes de que el
  modal fuera visible). Invertido el orden en `routeMap` y `deviceMap` (`resources/js/app.js`).
- Reprogramar una parada fallida/cancelada a otro día **se colaba directamente en una ruta existente**
  del chofer ese día si la había, saltándose la revisión de oficina. Ahora `rescheduleStop()` va
  siempre a "Sin asignar".

**Ruta permanente camión↔chofer + Historial (misma sesión, dos iteraciones):** primero se probó una
tabla `truck_assignments` aparte que generaba sola una fila de `routes` cada día; el usuario lo vio en
producción (varias filas `R-2026091X-C-01` para el mismo camión+chofer) y pidió volver a "una ruta =
una fila, aparece todos los días tenga viajes o no" + una herramienta para ver el resumen de un día
concreto. Se rediseñó: `Route` pasa a ser la ficha permanente (camión+chofer+tipo de servicio, sin
fecha) y lo que antes era `Route` (una fila por camión+día) pasa a ser `RouteDay` — migración de datos
con backfill incluida. Nueva herramienta "Historial" (`/rutas/{route}/historial`). Detalle completo en
`CLAUDE.md` → "Route permanente / RouteDay". 308 tests en verde localmente; **todavía sin commitear ni
desplegar** al cierre de esta sesión.

**Falta** (necesita datos externos, no bloquea el uso normal): SMTP ya funciona; Cloudflare R2 sigue
sin configurar (PDFs/firmas van a disco local del VPS, decisión consciente — un albarán no tiene los
plazos de conservación fiscal de una factura); APK de producción ya compilada apuntando al dominio,
pendiente de instalar en más camiones según se necesite.

---

## Punto de continuación (última sesión: 2026-09-09, noche)

**Estado:** Bloques 1–13 terminados. Servidor: **277 tests en verde**
(`cd server && docker compose exec -T laravel.test php artisan test`). APK Flutter: **53 tests Dart**
(`cd mobile && export JAVA_HOME=~/tools/jdk-17.0.20.1+1 && flutter test`). Todo en `develop`, sin push.

### El tracker GPS FUNCIONA en campo (2026-09-09)
El APK enrola, se le asigna chofer y **envía posiciones de forma fiable** (probado con Lucía Gómez,
device #16, ~50 posiciones en 40 min). Bugs de campo corregidos esta sesión:
- `MainActivity` en el paquete equivocado (`servalillo_tracker` vs namespace `tracker`) → crash al
  abrir. Movida. (`70c35b8`)
- El seed asignaba dispositivo falso a los 4 chóferes → no se podía asignar uno real. Ahora solo 2.
  (`30f213c`)
- **`openAppDatabase()` lanzaba `PRAGMA journal_mode=WAL` con `execute()`** → en Android devuelve una
  fila → `DatabaseException` → el servicio moría antes de muestrear. Quitado (sqflite gestiona WAL).
  Detectado con `adb logcat` (Galaxy S20 FE, wifi, `adb pair`). (`b4fb8b6`)
- El servicio arranca con `PermissionsState.canTrack` (ubicación "mientras se usa" + notificaciones),
  no `allGranted`. (`617e7fd`)
- El túnel de VS Code va inservible de lento (>100 s/petición) → **HTTP directo en la LAN** para
  pruebas. `mobile/dart_define.json` + `server/.env` `APP_URL` = `http://<IP-LAN>:8000`. Recompilar
  el APK cada vez que cambie la IP. (`26f109a`)

### Funciones nuevas sobre el tracker
- **"Localizar"** en Mantenimiento → Dispositivos: mapa Leaflet con última posición + rastro. (`4897002`)
- **"Ruta eficiente" desde la posición real del camión** + **"Ver recorrido"** dibuja el camión (🚚)
  y el tramo por carretera hasta la 1ª parada pendiente. (`eb1dfe7`)
- **Botones "Abrir en Google Maps"** para navegar (chofer): "Seguir ruta en Google Maps" (waypoints),
  icono de navegación por parada, "Cómo llegar" en el modal de parada, y "Abrir en Google Maps" en
  "Ver recorrido". `App\Support\GoogleMaps` (sin API key, sin origin → GPS del móvil). (`38fd457`, `94b1f7a`)

### EN MARCHA: despliegue a producción en VPS (`edce4d7`)
**Plan** en `~/.claude/plans/harmonic-twirling-dove.md`. Runbook completo en
**`docs/04-despliegue-vps.md`**.
- VPS bare-metal (Hetzner CX22/CAX11, Ubuntu 24.04): nginx + PHP 8.3-FPM + PostgreSQL 16. **Sin
  Docker, sin Node, sin Reverb** (`BROADCAST_CONNECTION=log`). ~€6-9/mes.
- Decisiones: **Cloudflare R2** para PDFs/firmas (solo backup de BD); **assets se compilan en el
  portátil y se suben** — `server/deploy/deploy.sh` **se ejecuta en el portátil** (build + `rsync`
  de `public/build` + `composer/migrate/optimize` por SSH).
- **Ya hecho (suite 282 verde)**: `server/deploy/*` (deploy.sh, nginx.conf, worker+backup systemd,
  crontab), `server/.env.production.example`, `docs/04`. Código: `config/app.php` timezone →
  `env('APP_TIMEZONE','UTC')`; `AppServiceProvider` fuerza `https` en prod. `DeliveryTypeSeeder` +
  `ProductionSeeder` (`DatabaseSeeder` NO se ejecuta en prod). Comando `servalillo:crear-usuario`.
  APK: `network_security_config.xml` deja cleartext solo en `<debug-overrides>` → **release = solo
  HTTPS**; LAN = `flutter build apk --debug`; `dart_define.production.example.json`.
- **Prerequisito**: subir el trabajo (40+ commits en `develop` sin pushear, `origin/main` atrás):
  `git push origin develop` → merge `develop`→`test`→`main` → push. El VPS sigue `main` (repo público).
- **Falta**: crear el VPS en Hetzner (cuenta y clave SSH ya listas), aprovisionar (§2 de `docs/04`),
  primer deploy, `ProductionSeeder` + `crear-usuario`, nginx + certbot, systemd + cron, bucket R2,
  recompilar el APK release con el dominio.

**Pendiente transversal** (sin relación): paginación Livewire sale en inglés (ver Bloque 6).

---

## Punto de continuación (sesión: 2026-09-07)

**Estado:** Bloques 1–9 terminados (**155 tests en verde**). Esta sesión: Bloque 9 (gestión de
clientes) + tipo de servicio Reparto/Viajes (enum `ServiceKind` en clientes, rutas y paradas; filtro
en el tablero) + selector de día del chofer como carrusel coverflow. **Siguiente = Bloque 10** (API
Flutter con Sanctum) — sección "API para Flutter" de `docs/01`, y `routes/api.php` (casi vacío).
Reutilizar el array `rules()` de los `Form` objects donde tenga sentido; la lógica de negocio ya vive
en servicios.

**Ojo con el entorno:** el `composer require league/csv` de esta sesión se ejecutó por error en la
raíz del monorepo además de en `server/`, dejando `composer.json` / `composer.lock` / `vendor/`
sueltos en la raíz (no versionados, sin entrada en `.gitignore`). El paquete real está bien
instalado en `server/`. Conviene borrar esos tres artefactos de la raíz.

## Punto de continuación (sesión: 2026-09-06)

**Estado (cierre de sesión):** Bloques 1–8 terminados y verificados (**113 tests en verde**). Esta
sesión: Bloques 5 (estadísticas), 6 (mantenimiento), 7 (web del chofer + ruleta de odómetro) y 8
(albaranes). Antes se renombró `backend/` → `server/`, se pinó el volumen de Postgres, se arregló la
replicación en máquina limpia y se subió a GitHub (`SergioSevaRayos/servalillo-gestion`; `develop`).

- **Siguiente = Bloque 10** (API Flutter con Sanctum; era el Bloque 9 antes de intercalar clientes).
  Contrato en `docs/01` sección "API para Flutter"
  (`/api/auth/login`, `/api/routes/today`, `/api/stops/{stop}/complete|fail|signature`,
  `/api/routes/{route}/odometer`, etc.). "Todo con Form Requests + API Resources". Reutilizar el array
  `rules()` de los `Form` objects donde tenga sentido. La lógica de negocio ya está en servicios
  (`OdometerService`, `DeliveryNoteService`, `DeliveryTypeSchemaValidator`) — la API es otra capa de
  entrada sobre lo mismo. `routes/api.php` existe pero está prácticamente vacío. Auth de dispositivo
  (token Sanctum propio con habilidad `gps:ingest`) es más bien del Bloque 10.

<details><summary>Historial Bloque 4 (sesión anterior)</summary>

El usuario pidió
explícitamente el tablero Kanban tras ver que el Bloque 3 solo traía la tabla CRUD — quedó claro que
Bloque 3 = ficha, Bloque 4 = tablero, y ambos están ya completos. Tras la primera pasada, tres rondas de
pulido de UX ya aplicadas: solo se arrastran paradas "Pendiente" (con tono apagado, no candado, para las
demás), scroll vertical interno por columna en vez de crecer la página, scrollbar a juego con el sistema
de diseño, animación de "hacer hueco" al arrastrar (`easing` propio, sin pelearse con las transiciones
CSS de la tarjeta), y se eliminó el estado `RouteStopStatus::InProgress` ("En curso") de las paradas.
Al pulir la animación aparecieron y se corrigieron dos bugs reales (ver `CLAUDE.md`, sección Bloque 4):
`chosenClass` de SortableJS no puede llevar varias clases separadas por espacio (rompía el drag entero
con `InvalidCharacterError`), y `tailwind.config.js` no escaneaba `resources/js/**/*.js`, así que
cualquier clase referenciada solo desde JS se podaba del CSS sin avisar. Última pasada de pulido: el
anillo de la tarjeta "cogida" se recortaba contra el borde de la columna al arrastrar — arreglado con
`ring-inset` (el anillo se dibuja hacia dentro) + un pequeño padding interno en la lista de tarjetas
(`px-1.5 -mx-1.5`, compensado para no desalinear la lista con la cabecera/botón de la columna). De paso
se corrigió un carácter suelto (`´`) que había roto la sintaxis de `VerifyEmailController.php` — no
relacionado con este trabajo, apareció al tener el archivo abierto en el editor, tumbaba toda la suite.

**Bloque 4 se considera terminado y pulido** tras esa sesión — 62 tests en verde, comprobado
manualmente en el navegador (dark mode, arrastre dentro/entre columnas, scroll de columna).

</details>

**Pendiente / dónde retomar:**
- `config/delivery.php` ya define los canales, pero `EmailChannel`/`PhysicalChannel` tienen el envío real
  comentado (placeholder) — se completa en el Bloque 8.
- Rutas placeholder pendientes de desarrollar: `chofer.today` (Bloque 7 — su tablero de una sola columna
  puede reutilizar buena parte de `<x-routes.stop-card>` y el patrón de modal del Bloque 4), grupo
  `maintenance.*` (Bloque 6).
- El tablero no tiene aún noción de "capacidad del camión" ni valida que la suma de `planned_quantity`
  de una columna no supere `trucks.capacity_liters` — no estaba en el alcance pedido, valorar si hace
  falta antes del Bloque 8 (albaranes).
- Git: en GitHub (`SergioSevaRayos/servalillo-gestion`). Flujo **sin ramas de feature**: se commitea
  directo en `develop`; `develop → test` (probar / VPS) y `test → main` (visto bueno) solo por merge.

**Cosas a tener presentes:**
- `npm run dev` puede quedar corriendo en segundo plano entre sesiones. Si `public/hot` existe pero no
  hay proceso vite escuchando en :5173, la página sale sin estilos — borra `public/hot` y usa
  `npm run build`, o relanza `npm run dev`. Con SortableJS añadido a `app.js`, un `npm run build`/`dev`
  desactualizado significa que el drag&drop simplemente no aparece (sin error visible) — si tocas
  `resources/js/app.js`, recuerda reconstruir.
- El selector de tema depende de la cookie `theme` **sin cifrar**; si algún middleware nuevo vuelve a
  envolver `EncryptCookies` sin el `except`, el toggle se rompe silenciosamente. Para testear cookies
  excluidas del cifrado usa `withUnencryptedCookies()`, nunca `withCookie()` (ver Bloque 4 / CLAUDE.md).
- `.glass` no debe usarse en tablas/formularios ni en la operativa del chofer (Bloque 7) — usar `.surface`
  (vía `<x-ui.card>`/`<x-ui.table>`). Las tarjetas del tablero (`<x-routes.stop-card>`) sí usan `.surface`
  (contenido denso), y las cabeceras de columna sí usan `.glass` (son "chrome").
- Patrón de modal: **siempre** abrir/cerrar con `$this->dispatch('open-modal'|'close-modal', 'nombre')`
  desde el componente Livewire, nunca con un `:show="$propiedadLivewire"` — no reacciona a cambios
  posteriores porque Alpine conserva su estado a través del morph de Livewire (ver Bloque 3 más arriba).
- Para leer una cookie desde el servidor usa **`request()->cookie()`**, nunca `Cookie::get()` (no existe
  en `CookieJar`) — detalle completo del gotcha (tema perdido al navegar con `wire:navigate`) en `CLAUDE.md`.
- **Nunca uses la clase `transition` (genérica) de Tailwind en un elemento arrastrable con SortableJS** —
  incluye `transform` por defecto y compite con la animación FLIP que Sortable aplica a las tarjetas
  vecinas para "hacer hueco", dando tirones. Usa `transition-shadow`/`transition-colors` (la propiedad
  concreta que necesites), nunca la genérica, en cualquier elemento que vaya a ser `data-stop-list`/`li`
  de un futuro tablero Kanban (Bloque 7 incluido).
- `.themed-scrollbar` (en `resources/css/app.css`) es la clase reutilizable para cualquier contenedor
  con scroll propio que deba llevar la barra a juego con el sistema de diseño (claro/oscuro) — úsala en
  vez de dejar la barra nativa del navegador la próxima vez que aparezca un contenedor con overflow.
