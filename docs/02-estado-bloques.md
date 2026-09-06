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
| 8 | Albaranes (PDF + canales email/físico) + colas | ⬜ Siguiente |
| 9 | API Flutter (Sanctum) | ⬜ |
| 10 | App Flutter de tracking | ⬜ |

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
  **nullable**, con `nullOnDelete()` en vez de `cascadeOnDelete()` (si se borra una ruta, sus paradas
  pasan a "Sin asignar" en vez de desaparecer). `RouteStop::scopeUnassigned()` = `whereNull('route_id')`.
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
  `field_schema`, validados por `DeliveryTypeSchemaValidator`) / Fallida / Omitida (con motivo →
  `failure_reason`). Se puede reabrir una parada cerrada. **No toca `delivery_notes`** (Bloque 8).
- `<x-chofer.stop-card>` = tarjeta táctil grande (sin drag), distinta de la del Kanban.
- 13 tests nuevos (`tests/Feature/ChoferTodayTest.php`). **Suite total: 99 passed.**

### Cómo probar el Bloque 7

1. Entra como `lucia@servalillo.test` → su ruta de hoy sale como "Publicada", sin empezar.
2. "Empezar jornada" → introduce un contador ≥ el del camión → la ruta pasa a "En curso".
3. Toca una parada → "Entregada" (ajusta litros y campos) / "Fallida" / "Omitida" con motivo.
4. `pedro@servalillo.test` ya tiene la jornada empezada (1 parada hecha, 3 pendientes).
5. "Terminar jornada" → contador de fin → comprueba que el odómetro del camión se actualiza.
6. `carlos@servalillo.test` no tiene ruta hoy → estado vacío.

---

## Punto de continuación (última sesión: 2026-09-06)

**Estado (cierre de sesión):** Bloques 1–7 terminados y verificados (**99 tests en verde**). Esta
sesión: Bloque 5 (panel estadístico), Bloque 6 (panel de Mantenimiento) y Bloque 7 (web operativa del
chofer). Antes se renombró `backend/` → `server/`, se pinó el volumen de Postgres, se arregló la
replicación en máquina limpia y se subió a GitHub (`SergioSevaRayos/servalillo-gestion`; se trabaja
en `develop`).

- **Siguiente = Bloque 8** (Albaranes). Al completar una parada hay que: crear el `delivery_note`
  (número `ALB-2026-000123`, `customer_snapshot` congelado, canal `email`/`physical`), capturar la
  **firma** del cliente (`deliveries.record_signature`, PNG validado a disco `r2`, nombre
  `ulid().png`), generar el **PDF** (Spatie) y **entregarlo** vía el canal (Job en la cola). Contrato
  y canales ya existen: `App\Contracts\DeliveryChannel` + `EmailChannel`/`PhysicalChannel` +
  `config/delivery.php` (envío real está comentado, placeholder). Enum `DeliveryNoteStatus` ya está.
  El `StopActionForm` del Bloque 7 es el punto donde engancharlo.

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
