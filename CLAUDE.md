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

### Sistema de diseño (Bloque 2)
- **Tailwind v3** real (postcss + `tailwind.config.js`), no v4 — `@tailwindcss/vite` está en
  `package.json` sin usar, es residuo del template de Breeze; ignóralo.
- Paleta `primary` (azul-petróleo) en `tailwind.config.js`; semánticos = escalas nativas de Tailwind
  (`emerald`=success, `amber`=warning, `rose`=danger). Tipografía = pila de fuentes de sistema, sin CDN.
- Dos composiciones en `resources/css/app.css` `@layer components`: **`.glass`** (chrome flotante: nav,
  KPIs, toasts, selector de tema, `x-dropdown`) y **`.surface`** (sólido/alto contraste: formularios,
  tablas, web del chofer). No mezclar — la web del chofer y las tablas nunca llevan `.glass`.
  - **El panel de `<x-modal>` y el desplegable de la campana** llevan `glass` (borde/sombra elevada)
    **+ `bg-white dark:bg-slate-900` opaco encima** (las utilidades ganan al `bg-white/70` de `.glass`):
    un panel a pantalla completa translúcido dejaba ver el contenido de detrás. El overlay del modal es
    `bg-slate-950/70`.
- Componentes reutilizables en `resources/views/components/ui/*` (`button`, `input`, `select`, `textarea`,
  `checkbox`, `badge`, `card`, `glass-panel`, `stat-card`, `table`, `theme-toggle`, `toast-container`,
  `drop-menu`, `date-input`, `digit-wheel`, `signature-pad`). Los primitivos de Breeze
  (`x-text-input`, `x-primary-button`, `x-dropdown`, `x-modal`, etc., namespace raíz sin `ui.`) están
  restilizados in-place y los siguen usando las páginas de auth.
- **`<x-ui.date-input>`** sustituye a **todo** `<input type="date">` (el calendario nativo del
  navegador no se puede estilar). `Alpine.data('datePicker')` (`app.js`): botón con la fecha
  formateada + popover con rejilla de mes, tokens del sistema, claro/oscuro, `min`/`max`, "Borrar"
  y "Hoy". `value` = fecha ISO (`'YYYY-MM-DD'` o `''`); se integra con Livewire vía
  `x-modelable="value"` + `wire:model` (acepta `.live` para los filtros). **El panel se
  teletransporta a `<body>`** (`x-teleport`) con `position:fixed` calculado del trigger, para no
  quedar recortado por el `overflow-hidden`/`transform` de un modal; el cierre por clic-fuera y el
  reposicionado al hacer scroll se gestionan con listeners propios en el componente (no
  `x-on:click.outside`, que no es fiable con teleport).
- `<x-ui.table>` + `.table-responsive` (CSS puro, sin JS): cada `<td>` necesita `data-label="Cabecera"`
  para que se muestre como tarjeta en móvil. Los enums de estado (`RouteStatus`, `RouteStopStatus`,
  `DeliveryNoteStatus`) tienen `->badgeVariant()` para pintarlos con `<x-ui.badge>`.
- Tema claro/oscuro: cookie `theme` (**excluida del cifrado**, ver `bootstrap/app.php` →
  `encryptCookies(except: ['theme'])`, porque JS la lee/escribe directo) + `Alpine.store('theme')`
  (`resources/js/app.js`) + `POST /theme` (`ThemeController`) que persiste en `users.theme_preference`
  si hay sesión. Script anti-FOUC en `resources/views/partials/theme-init.blade.php`.
  - **Gotcha ya resuelto**: `wire:navigate` sincroniza los atributos de `<html>` entre la página actual
    y la recién cargada; si la clase `dark` solo la pone JS en tiempo de ejecución (nunca en el HTML
    servido), esa sincronización la borraba en cada navegación SPA — el tema se veía saltar a claro al
    moverse entre secciones. Arreglado en dos frentes: `App\Support\Theme::htmlClass()` renderiza la
    clase en el propio `<html class="{{ ... }}">` de `layouts/app.blade.php`/`layouts/guest.blade.php`
    a partir de la cookie (para que la petición AJAX de `wire:navigate`, que sí manda la cookie, ya la
    lleve puesta), y `app.js` reaplica la clase en el evento `livewire:navigated` por si acaso. Para leer
    la cookie usa **`request()->cookie('theme')`**, nunca `Cookie::get()` (no existe ese método en
    `CookieJar`, solo sirve para encolar cookies salientes).
  - Al testear esto: `withCookie()` de Laravel **cifra** el valor antes de enviarlo, ignorando el
    `except` de `EncryptCookies` — para simular la cookie `theme` (excluida) en un test hay que usar
    `withUnencryptedCookies(['theme' => 'dark'])`, si no el test recibe `null` y da un falso negativo.
- Menú móvil = `<x-ui.drop-menu>`: gota → 3 gotas con rebote (`ease-[cubic-bezier(0.34,1.56,0.64,1)]`,
  solo `opacity`/`transform`). Referenciado desde `livewire/layout/navigation.blade.php`.
  - **El botón (FAB) y el panel son sólidos, NO `.glass`.** El `<x-ui.drop-menu>` se renderiza dentro
    de un `<template x-teleport="body">` **fuera del `<nav>` sticky**: un `position: fixed` anidado en
    un `position: sticky` lo coloca mal en Safari iOS y "salta" al hacer scroll. (El `<nav>` sigue
    siendo la única raíz del componente Livewire — no se puede envolver en un `<div>`: eso "atrapa" el
    sticky en una caja de la altura de la barra; y `display: contents` en el padre **rompe** el sticky
    en Chromium.)
  - **La nav sigue siendo el "pill" flotante** (`pt-4`/`px-4` en el `<nav>`, `rounded-2xl` en el
    `.glass`) también en móvil — decisión de diseño confirmada. PERO en móvil se hace **OPACA**
    (`max-md:bg-white dark:max-md:bg-slate-900`, no `bg-white/70`): con `/95` el contenido translucía
    por detrás al hacer scroll y parecía que la barra se movía. La regla
    `nav.sticky > .glass, .drop-menu-fab` en `app.css` anula además cualquier `backdrop-filter`
    residual (`none !important`, con y sin prefijo `-webkit-` — PostCSS descartaba la versión sin
    prefijo): un `backdrop-filter`, aunque sea identidad y no se vea sobre fondo opaco, en un
    `sticky`/`fixed` se repinta cada frame de scroll y "vibra".
- **Regla de modales: en escritorio el modal NO debe forzar scroll evitable.** Antes de dejar un
  `<x-modal>` con formulario, primero **aprovecha el ancho, no el alto**:
  - Modal ancho (`max-width="3xl"`/`4xl`) + rejilla `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`,
    agrupando los campos cortos en la misma fila (Cliente/Dirección, Contacto/Teléfono/Estado…).
  - `gap-x-4 gap-y-3` (no `gap-4`), `mt-5` entre bloques (no `mt-6`), `textarea` a `:rows="2"`.
  - Objetivo: que un formulario normal quepa entero en una ventana de portátil (~620 px de alto
    útil) sin barra de scroll. Verifícalo con una captura a **1366×620**.
  - Si aun así no cabe (formularios de muchísimos campos, p. ej. la ficha de cliente), el scroll va
    **solo en el contenedor de campos** (`max-h-[65vh] overflow-y-auto themed-scrollbar px-1 -mx-1`),
    con la cabecera y los botones **siempre visibles** — nunca scroll del modal entero ni botones
    que queden fuera de pantalla.
  El modal `stop-form` del Kanban (`livewire/routes/board.blade.php`) es la referencia del caso que sí
  cabe; el de la ficha de cliente, del caso con scroll contenido.
- `/style-guide` (rol administrador/mantenimiento) muestra todos los componentes — punto de referencia
  visual antes de tocar nada del sistema de diseño.
- `lang/es/{auth,passwords,validation,pagination}.php`: la app no traía ningún lang propio; sin esto los
  mensajes de validación y de sistema salían en inglés pese a `APP_LOCALE=es`.
- `resources/views/vendor/pagination/tailwind.blade.php`: vista de paginación por defecto de Laravel,
  publicada y restilizada con los tokens del proyecto (se detecta por convención, no requiere registro).

### Panel Administrador (Bloque 3)
- 4 módulos: `/chofers`, `/camiones`, `/usuarios` (exclusivo administrador/mantenimiento), y la ficha de
  rutas en `/rutas/listado`. Todos con búsqueda+filtro+orden+paginación.
- **`/rutas/listado` es el CRUD de la ruta *permanente*** (camión+chofer+tipo de servicio, sin fecha —
  ver "Route permanente / RouteDay" más abajo). No tiene `code` propio: se muestra como
  `{truck.code} · {driver.name} · {service_kind label}`. El código con fecha (`{R|V}-{YYYYMMDD}-
  {camión}`) lo sigue generando `App\Support\RouteCode::build()`, pero ahora solo para el `RouteDay`
  de cada día (`RecurringRouteService`), no para la ficha permanente.
- **Chofers vs Usuarios**: un chofer es un `User` (rol `chofer`) + `Driver`. Se crea/edita **solo** desde
  `/chofers` (transacción User+Driver en `DriverForm::save()`). `/usuarios` gestiona exclusivamente
  `administrador`/`mantenimiento` y nunca lista ni toca chofers — evita dos pantallas escribiendo la
  misma fila de `users`.
- **Patrón de modal** (`<x-modal name="...">`): abrir/cerrar SIEMPRE con
  `$this->dispatch('open-modal'|'close-modal', 'nombre-del-modal')` desde el componente Livewire.
  Nunca uses un `:show="$propiedadLivewire"` para controlar apertura tras el primer render — Alpine
  conserva su estado (`show`) a través de los re-renders/morph de Livewire, así que cambiar esa prop
  no reabre ni cierra nada después del primer paint. Esto es el mismo mecanismo que ya usaba Breeze
  para el modal de borrar cuenta; todos los módulos de gestión lo siguen.
- Borrado = `wire:confirm="..."` (nativo de Livewire 3) + `$this->dispatch('toast', message:, variant:)`.
- **`users.email` tiene un índice único parcial** (`WHERE deleted_at IS NULL`, migración
  `2026_09_10_130000_...`), no un `unique()` normal — con `SoftDeletes` en `User`, un `unique()` a
  secas bloquea ese email **para siempre** aunque la cuenta esté borrada (bug real en producción:
  borrar un usuario por error dejaba su email inservible). `UserForm`/`DriverForm` ignoran
  `deleted_at` al validar (`Rule::unique(...)->whereNull('deleted_at')`) para que ambas capas
  coincidan. Mismo riesgo latente sin arreglar (a valorar si molesta) en `drivers.employee_code` y
  `clients.external_ref`, que sí son `unique()` normales sobre modelos con `SoftDeletes`.
- **`<x-ui.password-input>`** (usado en `/usuarios` y `/chofers`): botón de ojo mostrar/ocultar +
  botón opcional `suggest` que genera una contraseña de 12 caracteres (letras+dígitos garantizados,
  cumple `Password::defaults()`: mín. 10, letras y números — `AppServiceProvider::boot()`). Todo en
  JS local del componente (`x-data` inline, sin registrar nada en `app.js`); el botón "generar"
  escribe en el `<input>` y dispara un evento `input` para que `wire:model` (diferido) lo recoja,
  sin tocar `$wire` directamente.
- Helpers de test centralizados en `tests/Pest.php` (no los dupliques en archivos individuales):
  `makeUser(string $role): User`; `makeRoute(string $date = '2026-09-10'): RouteDay` (crea la ruta
  *permanente* con truck+driver y su `RouteDay` de esa fecha — lo que necesita casi todo test que
  monta paradas/jornada); `makePermanentRoute(): Route` (solo la ficha permanente, sin ningún día);
  `makeRouteDay(Route $route, string $date = '2026-09-10'): RouteDay` (un día más de una ruta
  permanente ya creada, para tests con varios días de la misma ruta).

### Tablero Kanban de rutas (Bloque 4)
- **`/rutas` (`routes.board`) es ahora la vista principal** de "Rutas" — `App\Livewire\Routes\Board`.
  Columnas = **`RouteDay`** de la fecha seleccionada (`#[Url] $date`) + columna fija **"Sin asignar"**
  (`route_stops.route_id IS NULL`, backlog global sin filtrar por fecha). Tarjetas = `route_stops`,
  vía `<x-routes.stop-card>`. La ficha CRUD del Bloque 3 sigue viva en `/rutas/listado` (`routes.index`,
  ahora sobre la ruta *permanente* — ver "Route permanente / RouteDay").
- `route_stops.route_id` es **nullable** desde la migración `2026_09_06_090000_...` (antes era
  obligatorio) y su FK es `nullOnDelete()` (antes `cascadeOnDelete()`); apunta a `route_days`.
- **`RouteDay` es `SoftDeletes`**, así que el FK `nullOnDelete` NO se dispara al borrar desde la UI —
  pero hoy no hay ninguna acción de UI que borre un `RouteDay` suelto (solo la ruta *permanente* se
  borra desde `/rutas/listado`, y eso no toca sus `RouteDay` — ver esa sección). Este dato queda por
  si algún día se añade un "eliminar el día" en el tablero: habría que repetir el patrón que tenía
  el `Routes\Index::delete()` de antes de la reforma (sacar las pendientes a "Sin asignar", las
  cerradas se van con la fila).
- Crear/editar una parada (`App\Livewire\Forms\RouteStopForm`) es el primer sitio de la app que **usa de
  verdad** el modelo flexible de repartos: al elegir un `delivery_type_id` se renderizan dinámicamente
  los campos de su `field_schema` y se validan con `DeliveryTypeSchemaValidator` antes de guardarlos en
  `route_stops.data`. Si tocas el editor de tipos de reparto en el futuro, prueba también este formulario.
  - El modal `stop-form` es **ancho** (`<x-modal max-width="3xl">`) con rejilla `grid-cols-1
    sm:grid-cols-2 lg:grid-cols-3` y los campos cortos agrupados por fila (Cliente/Dirección,
    Contacto/Teléfono/Estado, Cantidad/Tipo de reparto) para que quepa sin scroll en escritorio —
    ver la "Regla de modales" en la sección del sistema de diseño. `<x-modal>` acepta
    `sm|md|lg|xl|2xl|3xl|4xl`.
  - **Al EDITAR** solo se tocan los datos del servicio (`status`, `planned_quantity`,
    `delivery_type_id`, `scheduled_for`, `data`): la identidad del cliente (`customer_name`,
    `address`, `contact_*`, `service_kind`) sale como bloque de **solo lectura** y
    `RouteStopForm::save()` **no la persiste** aunque llegue en el request (la ficha del cliente es
    su único punto de edición). Al **CREAR** sí se piden todos los campos.
  - **`scheduled_for`** (2026-09-10): editable desde el propio modal (`<x-ui.date-input>`), tanto al
    crear como al editar — sobre todo útil en **"Sin asignar"**: sin fecha el backlog aparece ahí
    todos los días (bajo demanda), con fecha solo aparece ese día (ver filtro del tablero, unas
    líneas más abajo). Antes solo lo ponían procesos automáticos (`RecurringStopService`,
    `StopActionForm::rescheduleStop`); ahora oficina también puede fijarlo a mano.
    **`RouteStopForm::autoAssignToRouteDay()`**: si tras guardar la parada sigue sin ruta
    (`route_id = null`) y se le ha puesto fecha, se busca entre las rutas *permanentes* del mismo
    `service_kind` vigentes esa fecha — con **una sola candidata**, se coloca sola en la columna de
    esa ruta ese día (`RecurringRouteService::ensureForDate()` + `RouteDay::reopenIfCompleted()` si
    hacía falta); con cero o varias, no hay forma de adivinar cuál y se queda en "Sin asignar" (ya
    con la fecha puesta) para que oficina la arrastre a mano. Poner fecha, en el caso normal de un
    solo camión activo de ese tipo, sustituye entonces al arrastre manual.
- **Drag & drop = SortableJS** (`npm install sortablejs`, importado en `resources/js/app.js`,
  función `initKanbanColumns`), no el plugin `@alpinejs/sort` — se descartó por no poder verificar con
  certeza su API exacta de arrastre multi-columna sin acceso a la documentación en vivo; SortableJS es
  inequívoco y es literalmente el que pedía el brief original.
  - Cada columna es `<ul data-stop-list data-route-id="{id}">` (sin el atributo = "Sin asignar").
    `onEnd` de Sortable arma un `CustomEvent('stops-reordered')` en `window` con las listas de
    `data-stop-id` de origen y destino; el componente lo captura con
    `x-on:stops-reordered.window="$wire.call('reorderStops', ...)"` y persiste posiciones + `route_id`.
  - **No hace falta reinicializar Sortable tras cada respuesta de Livewire**: los `<li>` llevan `wire:key`
    estable y el orden que devuelve el servidor coincide con el que dejó el arrastre en el DOM (se le
    pasó ese mismo orden), así que el morph no toca esos nodos. `initKanbanColumns` igualmente se vuelve
    a llamar en `livewire:navigated` y en `Livewire.hook('morph.updated', ...)` por si aparecen columnas
    nuevas (cambio de fecha), con una guarda `list._sortable` para no doble-inicializar.
  - Al llamar `$this->form->save(...)` desde un componente Livewire, los `Form` objects **no** reciben
    inyección de dependencias automática como un controlador — si el método `save()` pide un servicio
    (aquí `DeliveryTypeSchemaValidator`), resuélvelo a mano: `$this->form->save(app(Servicio::class))`.
- Móvil = **una columna a la vez con swipe** (decisión validada en `docs/03-ux-panel-rutas-kanban.md`):
  contenedor con `snap-x snap-mandatory`, columnas `snap-start` a ~88vw, más una barra de pestañas encima
  que hace `scrollIntoView` a la columna elegida. En escritorio, columnas de 320px en fila sin snap.
- Al testear `Board` con Livewire: `Livewire::test(Board::class, ['date' => '...'])` **no** funciona
  porque `mount()` no declara un parámetro `$date` (Livewire solo pasa por `mount()` lo que su firma
  acepta) — usa `->test(Board::class)->set('date', '...')` en su lugar.
- **Solo las paradas en estado `Pending` se pueden arrastrar** (no tiene sentido reasignar una ya
  completada/fallida). Doble barrera: `filter: '[data-draggable="false"]'` en SortableJS (cliente,
  `data-draggable` lo pone `<x-routes.stop-card>`) + comprobación real en `Board::reorderStops()`
  (servidor: una parada cerrada que cambiaría de ruta → 422). Las tarjetas no arrastrables se pintan
  con un **tono apagado** (fondo grisáceo + `grayscale-[0.3]`, sin candado ni icono) — decisión
  explícita del usuario.
  - **Las paradas cerradas quedan FIJAS: ni se cogen ni se desplazan.** `onMove` de Sortable devuelve
    `false` cuando el vecino afectado (`evt.related`) es `[data-draggable="false"]` → la tarjeta
    cerrada actúa como muro y no se anima apartándose para hacer hueco (antes se veía el salto).
    Segunda barrera en servidor: `Board::reindexColumn()` reconstruye la columna a partir del orden
    PREVIO (`position`) — cada cerrada conserva su sitio relativo al flujo de pendientes, las
    pendientes se reparten alrededor en el orden del arrastre (mismo criterio que
    `RouteOptimizer::optimize()`).
- **`RouteStopStatus::InProgress` ("En curso") se eliminó del todo** (a valorar si se reintroduce más
  adelante). No confundir con `RouteStatus::InProgress`, que es el estado de la *ruta* completa y sigue
  existiendo — son enums distintos para conceptos distintos.
- **Nunca uses la clase `transition` genérica de Tailwind en una tarjeta arrastrable con SortableJS** —
  incluye `transform` por defecto y compite con la animación FLIP que Sortable usa para que las tarjetas
  vecinas "hagan hueco" a la que se está soltando (se ve a tirones). Usa la utilidad específica que
  necesites (`transition-shadow`, `transition-colors`…), nunca la genérica, en cualquier `<li>` de una
  lista `data-stop-list` (o de un futuro tablero equivalente en el Bloque 7).
- **`forceFallback: true` es obligatorio para que el "hacer hueco" se vea bien.** Sin él, Sortable usa
  el drag-and-drop nativo del navegador: el "fantasma" que se arrastra es una captura que pinta el propio
  navegador (nuestras `ghostClass`/`chosenClass`/`dragClass` casi no se notan) y el `dragover` nativo
  dispara con menos frecuencia que un `pointermove`, así que el reflow de las tarjetas vecinas se ve a
  saltos — más aún al cruzar de una columna a otra. `forceFallback` hace que Sortable simule el arrastre
  entero con JS/CSS propios (control total del estilo y animación fluida). Junto con `fallbackOnBody: true`
  (para que la tarjeta arrastrada no se recorte contra el `overflow-y-auto` de la columna) y
  `fallbackTolerance: 3`. Si algún día el drag&drop "funciona pero se ve a tirones", esto es lo primero
  a revisar.
- **`chosenClass`/`ghostClass`/`dragClass` de SortableJS tienen que ser UNA sola clase CSS, nunca varias
  separadas por espacio.** Sortable hace `el.classList.add(options.chosenClass)` con el valor tal cual;
  `classList` lanza `InvalidCharacterError` si el string tiene espacios (rompía el drag entero: "aparecen
  un montón de errores y no funciona"). Si necesitas varios estilos a la vez, agrúpalos en una clase
  propia dentro de `@layer components` (ver `.stop-card-chosen` en `resources/css/app.css`) y pasa esa
  única clase a la opción de Sortable.
- **Cualquier clase de Tailwind que solo aparezca dentro de una cadena en un `.js` no se compila** a
  menos que `resources/js/**/*.js` esté en el `content` de `tailwind.config.js` (ya está añadido). Sin
  eso, Tailwind la considera "no usada" y la poda del CSS final sin avisar — la clase existe en el HTML
  en tiempo de ejecución pero no tiene ninguna regla asociada, así que no se ve nada (ni error, esto es
  lo insidioso). Si añades una clase nueva solo referenciada desde JS, o bien confirma que ya aparece
  también en algún `.blade.php`, o recuerda que ahora sí se escanea `resources/js/`.
- Comportamiento Trello real: el tablero no crece verticalmente. Cada columna es `flex flex-col` con
  altura fija (`h-[65vh] md:h-[calc(100vh-14rem)]`), cabecera y botón "+ Añadir parada" con `shrink-0`,
  y la `<ul data-stop-list>` con `flex-1 min-h-0 overflow-y-auto` (el `min-h-0` es imprescindible: sin
  él, un hijo flex no encoge por debajo de su contenido y `overflow-y-auto` no llega a activarse nunca).
  El scroll para moverse entre columnas sigue siendo horizontal, en el contenedor de columnas.
- La `<ul data-stop-list>` lleva `px-1.5 -mx-1.5` (padding + margen negativo que se cancelan en el borde
  exterior, dejando la lista alineada con la cabecera/botón de la columna) para que el `ring`/sombra de
  la tarjeta "cogida" tenga sitio donde respirar sin recortarse contra el borde de scroll de la columna.
  Además, `.stop-card-chosen` usa **`ring-inset`** (el anillo se dibuja hacia dentro del borde, no hacia
  fuera) — combinar ambas cosas es lo que evita el "perfil izquierdo recortado" al arrastrar una tarjeta.
- `.themed-scrollbar` (`resources/css/app.css`): clase reutilizable para dar a cualquier contenedor con
  scroll propio una barra fina a juego con el sistema de diseño (claro/oscuro), en vez de la nativa del
  navegador. Úsala en cualquier `overflow-y-auto`/`overflow-x-auto` nuevo antes de dejar la del sistema.

### Route permanente / RouteDay (2026-09-10 — reemplaza el diseño anterior de "asignación permanente")

- **Historia (dos iteraciones el mismo día):** primero se probó una tabla `truck_assignments` aparte
  (camión+chofer, sin fecha) que generaba sola una fila de `routes` (entonces = "un día") cada
  madrugada. El usuario lo vio funcionar en producción (`/rutas/listado` con 6 filas
  `R-20260910-C-01`…`R-20260915-C-01` para el mismo camión+chofer) y pidió dar marcha atrás:
  **"una ruta tiene que ser una única fila, y aparece todos los días tenga viajes o no"**, más una
  **herramienta para ver el resumen de un día concreto**. Se rediseñó invirtiendo qué tabla es "la
  ruta": ya no se crea una `TruckAssignment` aparte — la propia `Route` pasa a ser la ficha permanente,
  y lo que antes era `Route` (una fila por camión+día) pasa a ser `RouteDay`.
- **`App\Models\Route`** = la ficha permanente: `truck_id`, `driver_id`, `service_kind`, `valid_from`,
  `valid_until` (nullable = sin fin), `name`, `notes`, `created_by`, `SoftDeletes`. **Sin `code`
  propio** (se muestra como `{truck.code} · {driver.name} · {service_kind label}` — un código con
  fecha no tiene sentido en una ficha sin fecha). `Route::overlaps(column, id, from, until, ignoreId)`
  es el helper estático de solape de fechas (trata `valid_until = null` como "sin fin"), usado por
  `RouteForm` para rechazar dos rutas solapadas del mismo camión **o** del mismo chofer — así se seguía
  cumpliendo "1 camión = 1 ruta a la vez" sin depender de una fecha. CRUD en `/rutas/listado`
  (`App\Livewire\Routes\Index` + `RouteForm`), con **"Finalizar"** (fija `valid_until = hoy` sin abrir
  el modal). Borrar una `Route` la deja `SoftDeletes` pero **no toca su historial de `RouteDay`** —
  solo deja de generar días nuevos a partir de hoy.
- **`App\Models\RouteDay`** (antes `App\Models\Route`, tabla `route_days`) = la actividad de una ruta
  permanente **un día concreto**: paradas, jornada (litros inicio/fin), GPS, estado — todo lo que antes
  vivía en `Route` sigue igual aquí (`code`, `route_date`, `status`, `started_at`/`completed_at`,
  `liter_meter_*`, `deliveredLiters()`/`literMeterExpected()`/`literDiscrepancy()`, `scopeForDate()`,
  `scopeOperational()`). Tiene **`route_id`** (FK a la `Route` permanente que lo generó,
  `restrictOnDelete` — no se puede borrar en duro una ruta con historial). **`route_stops.route_id`,
  `odometer_readings.route_id` y `gps_positions.route_id` NO cambiaron de nombre de columna ni de
  relación** (`RouteStop::route()`, etc. — siguen llamándose igual): solo cambió a qué modelo apunta
  esa relación (`RouteDay`, no `Route`). Índice único `(route_id, route_date)` — sustituye al viejo
  `(truck_id, route_date)`.
- **`App\Services\RecurringRouteService`** sigue el mismo concepto que antes (y que `RecurringStopService`,
  paradas recurrentes, Bloque 9): `generateForDate`/`generateHorizon(14)`, aditivo e idempotente
  (`RouteDay::firstOrCreate(['route_id'=>, 'route_date'=>], …)` — **nunca pisa un `RouteDay` ya creado o
  editado a mano** para esa ruta y esa fecha). Solo cambió el objetivo: antes creaba `Route` a partir de
  `TruckAssignment`, ahora crea `RouteDay` a partir de `Route`. Mismo comando `rutas:generar-rutas
  {fecha?}` (scheduler diario 05:25) + disparo desde `Routes\Board` al cambiar de fecha. Días generados:
  `status = Published`, `service_kind` = el de la ruta permanente. Si se borra/edita la `Route` después,
  los `RouteDay` ya generados no se tocan.
- `App\Support\RouteCode::build()` — fórmula del código (`{R|V}-{Ymd}-{camión}`), ahora usada **solo**
  por `RecurringRouteService` (y `ensureForDate()`, red de seguridad) para el `code` de cada `RouteDay`
  — la `Route` permanente no tiene código.
- **Herramienta nueva — "Historial"** (`App\Livewire\Routes\History`, `/rutas/{route}/historial`,
  enlazada desde cada fila de `/rutas/listado`): lista paginada de los `RouteDay` de esa ruta permanente
  (fecha, estado, paradas completadas/falladas/pendientes, litros repartidos vs. contador inicio→fin,
  horario de jornada), con filtro de rango de fechas. "Ver detalle" abre un modal con la lista de
  paradas de ese día (enlace al PDF del albarán si lo tiene); "Ver recorrido" reutiliza
  `RouteGeometry::payloadFor()` + `<x-route-map-modal>` (misma infra que el tablero) para el mapa de
  ese día si hay GPS/paradas con coordenadas. Autorización = `RoutePolicy::view` (la misma de la ficha
  permanente); `RouteDayPolicy` gobierna el día individual dentro (`viewDay`/`showDayMap`).
- **Reparto de las Policies**: `RoutePolicy` (permanente) se quedó con `viewAny/view/create/update/
  delete/restore`. Lo que antes eran acciones "del día" (`reorderStops`, `optimizeOwn`, `operate`)
  pasó a **`App\Policies\RouteDayPolicy`** (auto-descubierta, sobre `RouteDay`) — mismo criterio de
  permiso + `owns()` que antes.
- **Migración de datos** (`2026_09_10_160000_redefine_route_as_permanent_entity.php`, un solo fichero):
  quita las FK de `route_stops`/`odometer_readings`/`gps_positions` → renombra `routes` a `route_days`
  → renombra `truck_assignments` a `routes` (+ añade `name`/`notes`/`service_kind`) → añade
  `route_days.route_id` → **backfill**: empareja cada `route_days` huérfano con la `routes` de su mismo
  `truck_id` cuyo `[valid_from, valid_until]` cubra esa fecha; si ninguna cubre (instalaciones con
  historial suelto sin asignación permanente detrás), crea automáticamente una ruta "histórica" por
  cada `(truck_id, driver_id)` distinto entre los huérfanos restantes → NOT NULL + FK + índice único
  nuevo → vuelve a crear las FK de las tres tablas hacia `route_days`. Si tocas esta zona del modelo de
  datos otra vez, este es el patrón a seguir (renombrar tablas + columna puente + backfill en la misma
  migración, no en dos).

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
- **Pendiente conocido (no es del Bloque 6):** la paginación de los listados Livewire sale en inglés
  ("Showing X to Y of Z results"). Livewire usa su propia vista `livewire::tailwind`, no la
  `resources/views/vendor/pagination/tailwind.blade.php` ya restilizada. Afecta también a los
  listados del Bloque 3. Se arregla apuntando Livewire a esa vista (o publicando
  `resources/views/vendor/livewire/tailwind.blade.php`) + un `lang/es.json` con las 4 cadenas.

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
- **Sincronización admin ↔ chofer = `wire:poll`** (no websockets): la web del chofer refresca cada
  **15 s** (`wire:poll.15s` en la raíz de `livewire.chofer.today`), el tablero cada **45 s**
  (`wire:poll.45s` en `livewire.routes.board`). Así lo que cambia oficina le aparece al chofer solo y
  viceversa, sin recargar. `wire:poll` se salta el ciclo si hay props "sucias" (formulario abierto en
  el tablero), lo que evita pisar la edición. Push instantáneo con Reverb queda como mejora futura
  (la infra está en `.env.example`/compose pero Echo no está cableado en `app.js`).
- Autorización: `RoutePolicy::operate` y `RouteStopPolicy::complete` (permiso + `owns()`), ya existían.
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
- **Añadir cliente sobre la marcha** (un cliente llama al chofer): botón "Añadir cliente (ha llamado)"
  **al final de la lista de paradas** (visible si `operable()`, es decir hoy o ruta `InProgress`) →
  modal `add-stop` con buscador (`clientMatches`, `Client::scopeSearch`, mín. 2 caracteres) →
  `addClientStop(Client)` crea una `RouteStop` `Pending` al final de la ruta con los datos del cliente
  (mismo copiado que `Clients\Show::planDelivery`, incl. `service_kind`). Autorización:
  `authorizeRoute()` (= `RoutePolicy::operate` + `operable()`); no hace falta permiso nuevo, el chofer
  ya tiene `routes.view.own`.
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

### Albaranes (Bloque 8)
- **Motor PDF = `barryvdh/laravel-dompdf`** (PHP puro), NO `spatie/laravel-pdf` — el contenedor no
  trae navegador headless (decisión de imagen ligera). La vista `resources/views/pdf/delivery-note.
  blade.php` usa CSS plano y **`font-family: Helvetica`** (fuente interna de dompdf, 0 KB embebidos,
  cubre el español). Con `DejaVu Sans` el PDF pesaba ~860 KB por la fuente embebida; con Helvetica, ~2 KB.
- **Flujo:** al completar una parada (`StopActionForm`), si no tiene albarán, `DeliveryNoteService::
  createForStop()` crea el `delivery_note` (número `ALB-{año}-{6 dígitos}` secuencial con `lockForUpdate`,
  `customer_snapshot` congelado, firma guardada en disco `r2`) en estado `Queued` y despacha
  `ProcessDeliveryNote` (cola `database`, contenedor `queue`).
- **`ProcessDeliveryNote`** (job, 3 reintentos): `Generating` → renderiza PDF a `r2` (`Generated`) →
  `$channel->deliver($note)` fija el estado final (`Sent` / `DeliveredPhysically`). `failed()` deja
  `Failed` + `failure_reason`.
- **Canales** (`config/delivery.php`): `EmailChannel` envía `DeliveryNoteMail` con el PDF adjunto a
  `recipient_email` (Mailpit en local). `PhysicalChannel` = **entrega en mano en papel**: no envía
  nada digital, solo marca `DeliveredPhysically`. Añadir un canal = clase nueva + entrada en config.
- **Firma según canal:** el contrato tiene `requiresSignature()`. `email` → sí (el cliente firma en
  el teléfono, canvas). `physical` → **no** (el chofer lleva albaranes en papel; el cliente firma el
  papel). En `physical` el formulario del chofer oculta la firma y solo pide "Recibido por"
  (opcional). `StopActionForm::rules()` y la vista se ramifican con `channelRequiresSignature()`.
- **Firma:** canvas + `signature_pad` (`<x-ui.signature-pad>`, Alpine `signaturePad` en `app.js`).
  Igual que la ruleta: `x-modelable` + `wire:model`, y se re-dimensiona al abrir el modal (canvas
  con `display:none` mide 0). Se exporta PNG data URL; el servicio valida la **cabecera mágica real
  del PNG** (`\x89PNG…`), nunca solo el prefijo del data URL.
- **Front del chofer:** el modal de "Entregada" incluye canal + email + firmante + firma. No aparece
  si la parada ya tiene albarán.
- **Panel admin:** `/albaranes` (`delivery-notes.index`, `permission:delivery_notes.view`) — listado
  con filtros, acciones "Reprocesar" (`delivery_notes.regenerate`) y "Marcar entregado" (físicos,
  `delivery_notes.mark_delivered`). PDF: `GET /albaranes/{note}/pdf` (fuera del grupo de rol; la
  `DeliveryNotePolicy` deja al manager o al chofer dueño de la parada; genera el PDF al vuelo si aún
  no existe).

### Gestión de clientes (Bloque 9)
- **Módulo independiente** (decisión del usuario): NO hay `route_stops.client_id`, el editor de
  paradas del Kanban no se tocó. El histórico por cliente se empareja en `Client::pastStops()` por
  `route_stops.customer_tax_id` (si el cliente tiene CIF) o por `route_stops.customer_name` exacto.
  Si algún día se quiere un enlace fuerte, es una migración + un `belongsTo` nuevos, no un refactor.
- `/clientes` (`App\Livewire\Clients\Index`) y `/clientes/{client}` (`App\Livewire\Clients\Show`,
  **página propia**, no modal) — grupo `role:administrador|mantenimiento`, `permission:clients.view`.
  Permisos `clients.{view,create,update,delete}` en `RolePermissionSeeder::PERMISSIONS`.
- Validación = `App\Livewire\Forms\ClientForm` (Form object, mismo patrón que el resto del panel, NO
  FormRequest). CRUD simple → sin Service intermedio, la lógica vive en el Form.
- `Client` implementa `Auditable` + `SoftDeletes`.
- **Calendario de reparto** — el cliente puede ser: **bajo demanda** (nada) / **días de la semana**
  (`delivery_weekdays` jsonb `[1..7]` ISO, con rango opcional `schedule_starts_on`/`schedule_ends_on`;
  `schedule_ends_on` null = indefinido) / **cada N días** (`frequency_days` + `last_served_on`, lo
  anterior). Si hay `delivery_weekdays`, gana sobre `frequency_days`. Helpers en `Client`:
  `deliveryWeekdays()`, `hasWeekdaySchedule()`, `scheduleActiveOn()`, `isScheduledOn()`,
  `nextDeliveryOn()`, `isDeliveryDue()` (todos ramifican weekday vs frequency), `frequencyLabel()`
  ("L·X·V · abr.–jun." / "Quincenal" / …). `Client::WEEKDAY_LABELS` (1→L … 7→D).
- **Generación automática de paradas recurrentes** (`App\Services\RecurringStopService`): para los
  clientes con `delivery_weekdays`, crea la parada del día (`route_id = null`, `scheduled_for` = ese
  día, `delivery_type_id` = agua). Idempotente (`stopExists` por CIF/nombre + `scheduled_for`). Se
  dispara desde `Routes\Board` al abrir/cambiar de día (`generateRecurringStops()`, guardado con
  `can('routes.update')`) y desde el comando `rutas:generar-recurrentes {fecha?}` (scheduler diario
  05:30, horizonte +14 días). **El tablero filtra "Sin asignar"** a `scheduled_for IS NULL` (backlog
  general) **OR** `scheduled_for = fecha vista` (recurrentes del día).
- **"Planificar reparto"** en la ficha crea un `RouteStop` con `route_id = null` (backlog "Sin
  asignar" del Kanban) copiando nombre/CIF/dirección/coordenadas/litros del cliente y
  `delivery_type_id = DeliveryType::waterId()`. Requiere `clients.update` **y** `routes.update`.
- **El producto siempre es agua**: se eliminó `clients.default_delivery_type_id`. `DeliveryType::waterId()`
  (slug `agua`) es el que usan `planDelivery`, `Chofer\Today::addClientStop` y el generador.
- **Import Access = comando, una sola vez** (decisión del usuario, NO subida por UI):
  `php artisan clientes:importar <archivo.csv> [--dry-run]`, archivo en la raíz del proyecto o ruta
  absoluta. `App\Services\ClientImporter` + `league/csv` (^9.0). Upsert por `external_ref` (con
  `withTrashed()` + `restore()`). Autodetecta delimitador `;`/`,`, mapea ~60 alias de cabecera
  ES/EN (`HEADER_MAP`), parsea números en formato español (`1.234,56`) y periodicidades textuales
  (`semanal`/`quincenal`/`mensual`). Filas inválidas se saltan y se reportan, no abortan. Plantilla
  en `docs/plantilla-clientes.csv`. El formato completo de columnas está en `docs/02` (Bloque 9).
- El seeder (`DatabaseSeeder::seedClients()`) crea ~57 clientes y **reasigna ~75% de las paradas
  del historial** a esos clientes (por `customer_name`/`customer_tax_id`) para que las fichas tengan
  histórico. Idempotente (`if (Client::query()->exists()) return`).
- `Audits::MODELS` incluye `'Cliente' => Client::class`.

### Pre-clientes / valoración (`App\Enums\ClientStatus`)
- Administración apunta por teléfono un posible cliente → queda como **"Pendiente valoración"**
  (`clients.status = 'prospect'`); tras consultar offline con los responsables: **Aprobar**
  (`status = 'customer'` + abre el modal de edición para completar) o **Descartar** (`forceDelete()`
  **permanente**, con `wire:confirm` — decisión del usuario, sin estado "descartado").
- Columna `clients.status` (string, **default `'customer'`**, indexada; migración
  `2026_09_07_130000_...`). Todo lo que trata a un cliente como "real" debe scopear con
  **`Client::scopeCustomers()`** (`status = customer`), NO con `->active()` (`is_active`, ortogonal).
  Ya aplicado en `Clients\Index::render()` (los prospectos no se ven salvo el filtro
  `status=prospect`) y en `Chofer\Today::clientMatches()` (`->customers()->active()`).
  `Client::isProspect()`; `Show::planDelivery()` y `Today::addClientStop()` abortan si es prospecto.
- **Alta = el mismo modal "Nuevo cliente"** con un `<x-ui.select wire:model.live="form.status">`
  "Cliente | Pendiente valoración" arriba (`x-clients.form-fields` recibe `:status` y `:editing` del
  padre; con `wire:model.live` el commit re-renderiza y el `@if ($status !== 'prospect')` colapsa el
  form a los campos de la llamada). El toggle solo aparece `@unless ($editing)`.
- Autorización: `ClientPolicy::approve` = `clients.update`; descartar = `clients.delete`. **No hay
  permiso nuevo.** `Index::approve/discard` y `Show::approve/discard`.
- **Cantidad en litros + unidad citada**: `clients.typical_quantity` **siempre en litros**;
  `clients.quantity_unit` ('L'|'m3') recuerda cómo lo dijo el cliente. En `ClientForm` NO hay prop
  `typical_quantity`: hay `$quantity_input` (número en la unidad) + `$quantity_unit`. `save()`
  multiplica ×1000 si `m3` y setea `typical_quantity` a mano (`unset($validated['quantity_input'])`);
  `setClient()` lo revierte (`/1000` si la unidad guardada es `m3`). La ficha usa
  `Client::quantityLabel()` → "3 m³ (3.000 L)". Campos de suministro nuevos: `water_type` (enum
  `WaterType` corriente/potable), `tank_distance_m` (metros), en la sección "Datos del suministro"
  del form y de la ficha, visible para prospectos y clientes.
- **Precio: tarifa fija o por litro**. `clients.price_per_liter` → **renombrado a `clients.price`**
  (migración `2026_09_07_140000_...`, `renameColumn`) + `clients.price_type` (enum `PriceType`:
  `fixed`|`per_liter`; los que ya tenían precio → `per_liter`). `save()` pone `price_type = null`
  si `price` es null. Ficha: `Client::priceLabel()` → "45,00 € (Tarifa fija)" / "0,9500 €/L".
- El **tipo de servicio** (`service_kind` Reparto/Viaje) ahora es visible **también en el alta de
  pre-cliente** (una llamada puede ser de un viaje).
- **Gotcha del componente `x-ui.select` con `placeholder`**: la `<option value="" disabled selected>`
  no se honra visualmente — el `<select>` MUESTRA la primera opción real aunque el modelo Livewire
  siga en `null` (se guarda `null`, solo el display engaña). Es pre-existente (afecta a
  `client_type`, `preferred_channel`…); `water_type` lo hereda.

### Tipo de servicio: Reparto / Viajes (`App\Enums\ServiceKind`)
- Hoy solo se opera **Reparto**; **Viajes** se gestionará más adelante. La clasificación ya existe en
  `clients.service_kind`, `routes.service_kind` y `route_stops.service_kind` (columna string, default
  `'reparto'`, migración `2026_09_07_110000_...`), casteadas al enum en los tres modelos.
- **Cliente**: selector "Tipo de servicio" en el alta/edición + filtro en `/clientes` (`#[Url] $kind`)
  + badge "Viaje" en el listado y en la ficha. `Client::scopeKind()`.
- **Tablero** (`Routes\Board`): `#[Url] $kind` (**`reparto` por defecto**, no `all` — es un filtro que
  muestra un tipo cada vez) + segmentado "Repartos | Viajes" (`setKind()`). Filtra tanto las columnas
  de ruta como el backlog "Sin asignar" por `service_kind`. Una parada nueva creada desde el tablero
  hereda el `kind` del filtro activo (`RouteStopForm::forColumn($routeId, $kind)`).
- **Ficha de ruta** (`/rutas/listado`) y **"Planificar" desde la ficha del cliente** también fijan
  `service_kind` (la parada planificada hereda el del cliente).
- Al arrastrar en el tablero no se cambia el `kind` (ambas caras del filtro son del mismo tipo, así que
  origen y destino ya coinciden). Si algún día "Viajes" necesita su propio flujo/campos, este enum es
  el punto por el que ramificar.

### Notificaciones y canal de soporte (Bloque 12)
- **Notificaciones = sistema nativo de Laravel**, tabla `notifications` (`php artisan notifications:table`,
  editada: `data` es **`jsonb`**, PK `uuid` **intacta** — `Illuminate\Notifications\DatabaseNotification`
  la exige, no crear modelo propio). Canal **`database` únicamente** (sin mail, sin broadcast), envío
  **síncrono** (las clases usan `Queueable` pero **no** `ShouldQueue`). `User` ya tiene `Notifiable`.
- **4 clases en `app/Notifications/`** (`ChoferRouteChanged`, `SupportTicketOpened`,
  `SupportTicketReplied`, `SupportTicketStatusChanged`). Todas devuelven en `toArray()` el **mismo
  esquema** para que la campana pinte cualquiera: `type`, `title`, `body`, `url`, `icon`
  (`route|wrench|chat|flag` → `<svg>` inline en `bell.blade.php`). El deep link de soporte lo resuelve
  el trait `Concerns\LinksToTicket` según el rol del `$notifiable` (admin → `/soporte`, resto →
  `/mantenimiento/soporte`).
- **Campana** = `App\Livewire\Notifications\Bell` (componente de **clase**, no Volt) **anidado dentro
  del componente Volt de navegación** (`livewire/layout/navigation.blade.php`, cluster derecho). Se
  monta solo para `isManager()`. `wire:poll.30s` (coherente con chofer 15s / tablero 45s). Su vista
  tiene raíz única `<div wire:poll.30s>`; el nav persiste entre `wire:navigate` (vive fuera de `$slot`)
  así que la campana y su poll sobreviven. `markRead($id)` marca leída y `$this->redirect(url, navigate:
  true)`.
- **Disparo de las notificaciones del chofer = dispatch explícito, NUNCA observer.** Los 4 tipos
  (`stop_failed`, `stop_skipped`, `stop_rescheduled`, `client_added`, `meter_discrepancy`) no se
  distinguen de un `updated`/`created` genérico y un observer se dispararía también para el tablero de
  oficina. `App\Support\Notifications\RouteChangeNotifier` se llama desde `StopActionForm::apply()`
  (rama failed/skipped, tras el `->update()`), `Today::addClientStop()` y `Today::endDay()` (solo rama
  `$adjusted`). Guard: `! auth()->user()?->isDriver()` → seeders/comandos/tablero no disparan nada.
  **Entrega completada normal y empezar/terminar jornada sin incidencia NO notifican.**
  `StopActionForm::apply()` recibe ahora un 3er argumento `RouteChangeNotifier` (el único llamador,
  `Today::saveStop`, lo pasa a mano con `app(...)`).
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
- **Chofer — "Ir a la base a repostar"**: botón aparte (`Today::optimizeFromBase()`, atajo de
  `runOptimize('base')`, con `wire:confirm`) para cuando el camión tiene que volver a la nave a
  rellenar. Un toque = reordena las pendientes saliendo de la base **sin** pasar por el modal; las
  completadas se quedan en su sitio. Visible si `operable() && ! finished && pendingCount > 1`.
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
  - **`vehicle`** (Bloque 11): última `GpsPosition` de la ruta (sin límite de antigüedad, se muestra
    "hace X") + `approach` = trazado por carretera desde el camión hasta la **primera parada
    pendiente** (`next_stop`). `routeMap` lo pinta como marcador 🚚 ámbar con "ping" + línea ámbar
    discontinua ("cómo llegar a la 1ª parada", con km/min en `approachNote`). `.route-map-vehicle`
    en `app.css`.
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

### API de tracking GPS (Bloque 10)
- **La única API de la app** (`routes/api.php`). El "Bloque 10 — API Flutter" del contrato original de
  `docs/01` §4 (rutas/paradas/albaranes del chofer) **NO se construyó**: la web del chofer (Livewire)
  cubre esa operativa. Flutter es solo una **APK "tracker" sin interfaz** que el servicio técnico
  instala en el móvil de cada camión.
- **`POST /api/device/register`** (sin auth, `throttle:device-register` = 10/min por IP): la APK envía
  `{ install_identifier, secret, platform?, app_version? }`. `secret` se compara con
  `config('servalillo.device.enrolment_secret')` vía `hash_equals` (secreto vacío → 403). Upsert del
  `Device` por `install_identifier` (**no se toca `driver_id`** — enrolamiento "en blanco"); revoca
  tokens anteriores; emite un token Sanctum en el **`Device`** (`HasApiTokens`) con habilidad
  `gps:ingest`. Devuelve `{ token, device_id, tracking: {ping_interval_seconds, ping_distance_meters,
  pause_start, pause_end} }`.
- **`GET /api/device`** (`auth:sanctum` + `abilities:gps:ingest`, añadido en el Bloque 11):
  `DeviceApiController@show`. Estado para la pantalla de la APK → array plano (NO Resource)
  `{ device_id, label, is_active, driver: {name}|null, tracking, server_time }`. `driver.name` sale de
  `device->driver->user->name`. **NO aborta** si `is_active` es false: devuelve 200 con
  `is_active:false` para que la APK pare con elegancia (sí aborta 403 si el token no es de un `Device`).
- **`POST /api/gps/batch`** (`auth:sanctum` + `abilities:gps:ingest` — aliases añadidos en
  `bootstrap/app.php`): `{ positions: [{lat, lng, recorded_at, accuracy_m?, speed_mps?, heading_deg?,
  battery_level?}] }`, máx **500** por lote. `StoreGpsBatchRequest` + `App\Services\GpsIngestService`:
  descarta posiciones con `recorded_at > now()+1h`; por cada fecha resuelve
  `$route = RouteDay::forDate($d)->where('driver_id', $device->driver_id)->first()` → `driver_id` (del
  device), `truck_id`/`route_id` (de la ruta, **null si el device no tiene chofer o el chofer no tiene
  ruta ese día**); `GpsPosition::insert()` en bloque (sin eventos, no auditada); bumpea
  `device.last_seen_at`. `abort_unless($device instanceof Device, 403)` en el controlador (un token de
  usuario nunca tiene `gps:ingest` en prod, pero se blinda).
- **Esquema**: `devices.truck_id` (unique, NOT NULL) → **`devices.driver_id`** (nullable, unique,
  `nullOnDelete`). `gps_positions.truck_id` → **nullable**. `Truck::device()` eliminado.
- **Panel** `/mantenimiento/dispositivos` (`App\Livewire\Maintenance\Devices`, tab, permiso
  `devices.manage` que ya tienen `mantenimiento` y `administrador`): lista de APKs enroladas (última
  señal, versión, nº posiciones, estado), `<select>` para **asignar/reasignar chofer** (`assign()` —
  avisa si el chofer ya tiene otro dispositivo, el `driver_id` es unique), **revocar** acceso
  (`$device->tokens()->delete()`), **activar/desactivar**, **eliminar** (borra sus `gps_positions` por
  cascade), y **"Localizar"** (`locate()`, solo si tiene posiciones): emite `open-device-map` con la
  última posición + un rastro de las ≤60 recientes; `<x-device-map-modal>` + `Alpine.data('deviceMap')`
  (mapa Leaflet, círculo de precisión, línea punteada del rastro) — misma infra que `<x-route-map-modal>`
  del Bloque 13.
- **`gps:purgar {--dias=}`** (`App\Console\Commands\PurgeGpsPositions`, schedule diario 04:00): borra
  `gps_positions` anteriores a `config('servalillo.gps_retention_days')` en lotes de 5000.
- `bootstrap/app.php` ya devuelve JSON en `api/*` y loguea toda excepción a `error_logs` → los errores
  de la API salen solos en el panel de Mantenimiento. El grupo `api` de Laravel aquí es solo
  `SubstituteBindings` (sin `throttle:api`).
- Tests: `tests/Feature/Api/{DeviceRegisterTest,GpsBatchTest,DeviceShowTest}.php`,
  `PurgeGpsPositionsTest.php`, `MaintenanceDevicesTest.php`. Token de device en tests:
  `Sanctum::actingAs($device, ['gps:ingest'])` o
  `$device->createToken('t', ['gps:ingest'])->plainTextToken` + `->withToken(...)`. `phpunit.xml` fija
  `DEVICE_ENROLMENT_SECRET=test-enrolment-secret`.

### APK tracker (Bloque 11) — `mobile/`

- **APK Android headless** (Flutter). La instala el servicio técnico en el móvil de cada camión; se
  abre una vez para permisos y luego comparte ubicación en 2º plano para siempre. **Sin login ni UI
  del chofer.** Consume `POST /api/device/register`, `GET /api/device`, `POST /api/gps/batch`.
- **Toolchain para compilar** (no hace falta Android Studio): JDK 17 (`~/tools/jdk-17.0.20.1+1`,
  Java 21/25 no valen para AGP) + Android SDK (`~/Android`, cmdline-tools, `platforms;android-36`).
  `export JAVA_HOME=~/tools/jdk-17.0.20.1+1` antes de cualquier `flutter`/`gradle`.
- **Config = compile-time** (`--dart-define-from-file=dart_define.json`, gitignored):
  `SERVER_URL` (default = dev tunnel), `ENROLMENT_SECRET` (= `DEVICE_ENROLMENT_SECRET` del servidor,
  sin default), `APP_VERSION`. Plantilla `mobile/dart_define.example.json`. Cambiar la URL/secreto
  exige recompilar.
- Build: `cd mobile && flutter build apk --release --dart-define-from-file=dart_define.json` →
  `build/app/outputs/flutter-apk/app-release.apk` (firmado con la clave debug — sideload).
- **El túnel de VS Code NO sirve para el tracker** (latencia >100 s/petición > timeout de 25 s del
  cliente → "no se pudo conectar" en bucle, aunque el device sí se crea en el servidor). Para probar
  sin VPS: PC + móvil en la misma Wi-Fi, `SERVER_URL=http://<IP-LAN>:8000`. El APK lleva
  `usesCleartextTraffic=true` + `res/xml/network_security_config.xml` para permitir `http://`;
  **quitar esas dos líneas del manifest para la build de producción** (servidor por HTTPS).
- **Gotchas de plataforma ya resueltos** (todos detectados con `adb logcat`, móvil por Wi-Fi):
  (1) `MainActivity` tiene que estar en el paquete del `namespace` (`es.servalillo.tracker`), no en
  `es.servalillo.servalillo_tracker` que genera `flutter create` — si no, `ClassNotFoundException` al
  abrir. (2) **NUNCA** `db.execute('PRAGMA journal_mode=WAL')` en `openAppDatabase()`: en Android
  devuelve una fila y `execute()` la rechaza → `DatabaseException` → el `onStart` del servicio muere
  antes de montar el controller (0 envíos). sqflite ya gestiona WAL solo. (3) el servicio arranca con
  `PermissionsState.canTrack` (ubicación "mientras se usa" + notificaciones), no con `allGranted`;
  "ubicación siempre" + batería se recomiendan pero no bloquean.
- **Arquitectura Dart**: servicio en 1er plano (`flutter_foreground_task`, `TaskHandler` +
  `@pragma('vm:entry-point') startCallback()`) → cada tick: `pause_window` → `location_sampler` →
  `position_queue` (SQLite, cola offline ≤50k) → `sync_service` (lotes ≤500, borra tras 2xx) →
  `tracker_controller` mapea el resultado a notificación / parar servicio / saltar N ticks.
  `enrolment_service` (UUID persistente + token en `flutter_secure_storage`). `status_screen` = única
  pantalla (permisos + estado). Todo lo testeable tiene test en `mobile/test/` (53, con
  `sqflite_common_ffi` y `MockClient`).
- `flutter analyze` limpio + `dart format` + `flutter test` antes de dar por bueno. Detalle de
  instalación, permisos y mataprocesos OEM en `mobile/README.md`.

## Convenciones
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
Runbook completo en **`docs/04-despliegue-vps.md`**. Resumen:
- **VPS bare-metal** (Hetzner CX23, Ubuntu 24.04): nginx + PHP 8.4-FPM (PPA `ondrej/php` — el lock
  exige >= 8.4.1) + PostgreSQL 16. **Sin Docker,
  sin Node, sin Reverb** (Echo no está cableado; `BROADCAST_CONNECTION=log`). Un `queue:work` bajo
  systemd + cron para `schedule:run`. Ficheros (PDF/firmas) en **Cloudflare R2** (disco `r2`).
- **Assets se compilan en el portátil y se suben** (el VPS no lleva Node). El deploy es
  **`server/deploy/deploy.sh`, que se ejecuta EN EL PORTÁTIL**: comprueba `main` limpio + push,
  corre los tests (`SKIP_TESTS=1` para saltarlos), `npm run build`, `git pull` en el VPS, `rsync` de
  `public/build/`, y por SSH `composer install --no-dev` + `migrate --force` + `optimize` +
  `queue:restart`. Config en `server/deploy/deploy.env` (gitignored; `VPS`/`APP_DIR`/`DOMAIN`).
- **Ficheros de infra** en `server/deploy/`: `nginx.conf`, `servalillo-worker.service`,
  `servalillo-backup.{sh,service,timer}` (pg_dump nocturno a R2), `crontab.txt`.
- **`.env` de producción**: plantilla en `server/.env.production.example` (`APP_ENV=production`,
  `APP_TIMEZONE=Europe/Madrid`, `SESSION_SECURE_COOKIE=true`, SMTP real, `R2_*`, `DEVICE_ENROLMENT_SECRET`…).
- **Seed de producción**: `php artisan db:seed --class=ProductionSeeder --force` (solo
  `RolePermissionSeeder` + `DeliveryTypeSeeder` — extraído de `DatabaseSeeder`, que NO se ejecuta en
  prod porque usa `fake()` y crea usuarios `.test`). El admin: `php artisan servalillo:crear-usuario`.
- **Código preparado para prod** (`AppServiceProvider`): `URL::forceScheme('https')` si
  `isProduction()`. `config/app.php` timezone pasa a `env('APP_TIMEZONE', 'UTC')`.
- **APK release = solo HTTPS**: `network_security_config.xml` deja el cleartext solo en
  `<debug-overrides>`. Pruebas LAN → `flutter build apk --debug`. Producción → `--release
  --dart-define-from-file=dart_define.production.json`.
