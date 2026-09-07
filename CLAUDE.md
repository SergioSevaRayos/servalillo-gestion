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
- `config/servalillo.php`: ventana de tracking GPS (pausa 22:00–05:00), intervalos, retención GPS 90 días.
- `config/filesystems.php` disco `r2`: usa S3/R2 si hay `R2_ACCESS_KEY_ID`, si no cae a `local`
  (`storage/app/private/r2`). Firmas y PDFs van a este disco con nombres generados por el sistema.

### Sistema de diseño (Bloque 2)
- **Tailwind v3** real (postcss + `tailwind.config.js`), no v4 — `@tailwindcss/vite` está en
  `package.json` sin usar, es residuo del template de Breeze; ignóralo.
- Paleta `primary` (azul-petróleo) en `tailwind.config.js`; semánticos = escalas nativas de Tailwind
  (`emerald`=success, `amber`=warning, `rose`=danger). Tipografía = pila de fuentes de sistema, sin CDN.
- Dos composiciones en `resources/css/app.css` `@layer components`: **`.glass`** (chrome flotante: nav,
  modales, KPIs, toasts, selector de tema) y **`.surface`** (sólido/alto contraste: formularios, tablas,
  web del chofer). No mezclar — la web del chofer y las tablas nunca llevan `.glass`.
- Componentes reutilizables en `resources/views/components/ui/*` (`button`, `input`, `select`, `textarea`,
  `checkbox`, `badge`, `card`, `glass-panel`, `stat-card`, `table`, `theme-toggle`, `toast-container`,
  `drop-menu`). Los primitivos de Breeze (`x-text-input`, `x-primary-button`, `x-dropdown`, `x-modal`,
  etc., namespace raíz sin `ui.`) están restilizados in-place y los siguen usando las páginas de auth.
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
  - **El botón y el panel son sólidos, NO `.glass`**, y la barra `<nav>` sticky pierde el blur por
    debajo de `md` (`max-md:backdrop-blur-none max-md:bg-white/95 …`): un `backdrop-filter` en un
    elemento `sticky`/`fixed` se repinta en cada frame de scroll en móvil y "vibra".
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
- Helpers de test centralizados en `tests/Pest.php` (no los dupliques en archivos individuales):
  `makeUser(string $role): User`, `makeRoute(string $date = '2026-09-10'): Route` (con truck+driver ya
  creados).

### Tablero Kanban de rutas (Bloque 4)
- **`/rutas` (`routes.board`) es ahora la vista principal** de "Rutas" — `App\Livewire\Routes\Board`.
  Columnas = rutas de la fecha seleccionada (`#[Url] $date`) + columna fija **"Sin asignar"**
  (`route_stops.route_id IS NULL`, backlog global sin filtrar por fecha). Tarjetas = `route_stops`,
  vía `<x-routes.stop-card>`. La ficha CRUD del Bloque 3 sigue viva en `/rutas/listado` (`routes.index`).
- `route_stops.route_id` es **nullable** desde la migración `2026_09_06_090000_...` (antes era
  obligatorio) y su FK es `nullOnDelete()` (antes `cascadeOnDelete()`): borrar una ruta ya no borra sus
  paradas, las deja en "Sin asignar".
- Crear/editar una parada (`App\Livewire\Forms\RouteStopForm`) es el primer sitio de la app que **usa de
  verdad** el modelo flexible de repartos: al elegir un `delivery_type_id` se renderizan dinámicamente
  los campos de su `field_schema` y se validan con `DeliveryTypeSchemaValidator` antes de guardarlos en
  `route_stops.data`. Si tocas el editor de tipos de reparto en el futuro, prueba también este formulario.
  - El modal `stop-form` es **ancho** (`<x-modal max-width="3xl">`) con rejilla `grid-cols-1
    sm:grid-cols-2 lg:grid-cols-3` y los campos cortos agrupados por fila (Cliente/Dirección,
    Contacto/Teléfono/Estado, Cantidad/Tipo de reparto) para que quepa sin scroll en escritorio —
    ver la "Regla de modales" en la sección del sistema de diseño. `<x-modal>` acepta
    `sm|md|lg|xl|2xl|3xl|4xl`.
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
  (servidor: si la posición/ruta de una parada cambiaría y su estado no es `Pending`, aborta 422) —
  nunca te fíes solo del filtro de cliente. Las tarjetas no arrastrables se pintan con un **tono
  apagado** (fondo grisáceo + `grayscale-[0.3]`, sin candado ni icono) — decisión explícita del usuario.
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

### Panel estadístico (Bloque 5)
- **`/dashboard` es ahora un componente Livewire** (`App\Livewire\Dashboard\Index`), ya no un
  `Route::view`. Sigue siendo la landing de gestión (redirección post-login de admin/mantenimiento) pero
  es el panel de KPIs completo. Ruta con `->middleware('permission:stats.view')` **y** `abort_unless`
  en `mount()` (los tests via `Livewire::test` se saltan el middleware de ruta).
- **`App\Services\FleetStatsService`** es el único sitio donde se agregan las métricas. `report(Carbon
  $from, Carbon $to)` devuelve un array con `kpis`, `operations` (serie diaria + estados de ruta),
  `volume` (planificado vs entregado + por tipo de reparto), `by_driver`, `by_truck`. Todo con
  **agregación en BD** (Query Builder + `filter (where ...)` de Postgres), no trayendo filas. Como
  `routes`/`route_stops` usan SoftDeletes y el Query Builder no las scopea, cada consulta añade
  `whereNull('...deleted_at')` a mano — si tocas el service, no lo olvides.
- Toda la operativa se cuenta por la **fecha de la ruta** (`routes.route_date`), no por `completed_at`,
  para que "el periodo" sea coherente entre paradas completadas y falladas.
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
  para que el panel tenga datos. `migrate:fresh --seed` deja ~230 rutas y ~1100 paradas.

### Panel de Mantenimiento (Bloque 6)
- **Exclusivo del rol `mantenimiento`** (no `administrador`). Grupo `maintenance.*` en `routes/web.php`
  con `role:mantenimiento`; enlace "Mantenimiento" en el nav solo si `auth()->user()->isMaintenance()`.
- 3 sub-vistas con pestañas compartidas (`<x-maintenance.tabs>`):
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
  **la ruta del chofer para un día** (`routes` donde `driver_id` = su `driver->id` y `route_date` =
  `#[Url] $date`, def. hoy) como una columna de paradas. Mobile-first, `.surface`, **nunca `.glass`**.
- **Selector de día = carrusel "coverflow"** (`.day-carousel*` en `app.css`, `Alpine.data('dayCarousel')`
  en `app.js`). El día en foco va grande y centrado; los vecinos, cada vez más pequeños, girados
  (`rotateY`) y difuminados. **Todo el gesto es cliente**: `offset` fraccional + animación CSS al
  arrastrar / girar la rueda del ratón encima / pulsar flechas / tocar un día; al soltar se redondea
  al día más cercano y se llama a `$wire.selectDay(fecha)` **una sola vez** (recarga la ruta + URL).
  - El `<div x-data="dayCarousel(...)">` lleva **`wire:ignore`** — es imprescindible: sin él, `initial`
    (`@js($this->date)`) cambia en cada commit, Livewire re-morfea el atributo `x-data` y Alpine
    reinstancia el componente perdiendo su estado y la animación.
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
  `skipped`. `completed` pide litros entregados + renderiza los campos del `field_schema` del tipo de
  reparto (mismo patrón que el editor del Kanban) y los valida con `DeliveryTypeSchemaValidator`.
  `failed`/`skipped` piden motivo (va a `failure_reason`). Se puede "Reabrir" una parada cerrada.
  **NO toca `delivery_notes`** — el albarán (registro, firma, PDF, envío) es entero del Bloque 8.
  `completed_at` solo se rellena en `completed` (coherente con el seeder y `FleetStatsService`).
- Autorización: `RoutePolicy::operate` y `RouteStopPolicy::complete` (permiso + `owns()`), ya existían.
- `<x-chofer.stop-card>` es la tarjeta táctil del chofer (grande, sin drag), distinta de
  `<x-routes.stop-card>` (Kanban del admin).
- **Contador de litros / cuadre:** es un contador de litros *dispensados* (como el cuentakilómetros,
  pero de litros), **independiente de la cisterna** (el camión puede rellenar o no durante el día).
  `trucks.liter_meter` guarda la última lectura conocida; `routes.liter_meter_start` /
  `liter_meter_end` / `liter_discrepancy_note` la jornada. "Empezar jornada" pide la lectura del
  contador (prefill = `trucks.liter_meter`); el panel del chofer muestra un contador **dinámico**
  ("al empezar X → va por X + repartido"). "Terminar jornada" pide la lectura real; si
  `(fin − inicio) − repartido` supera `config('servalillo.liter_meter_tolerance')` (def. 0), el modal
  avisa ("el contador marca N L más/menos de lo repartido") y **obliga a un motivo del ajuste** antes
  de cerrar; toast `warning` al finalizar con ajuste. Al terminar, `trucks.liter_meter` = lectura de
  fin. Helpers en `Route`: `deliveredLiters()`, `literMeterExpected()`, `literDiscrepancy()`. El
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
- `Client` implementa `Auditable` + `SoftDeletes`. `frequency_days` null = "bajo demanda";
  `nextDeliveryOn()` = `last_served_on` + `frequency_days` días; `isDeliveryDue()` alimenta el badge
  ámbar "le toca reparto" del listado y el filtro `schedule=due` (que usa `whereRaw` con intervalo
  de Postgres: `last_served_on + (frequency_days || ' days')::interval <= now()`).
- **"Planificar reparto"** en la ficha crea un `RouteStop` con `route_id = null` (backlog "Sin
  asignar" del Kanban) copiando nombre/CIF/dirección/coordenadas/tipo/litros del cliente. Requiere
  `clients.update` **y** `routes.update`.
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

## Convenciones
- Código y comentarios de dominio en **español**; nombres de clases/métodos en inglés estándar Laravel.
- Regla de negocio: **1 camión = 1 ruta por día** (índice único `routes.truck_id + route_date`).
- Tablas de alto volumen (`gps_positions`) y de log (`error_logs`) son append-only: `UPDATED_AT = null`, sin auditar.
- Los listados del panel deben tener búsqueda + filtro + orden + paginación, y en móvil convertirse en
  tarjetas (nada de scroll horizontal). Mobile-first.
- Registro público de usuarios está **deshabilitado** a propósito (los crea el admin).

## Tests
Pest. `tests/Pest.php` siembra `RolePermissionSeeder` en cada test Feature (`beforeEach`).
Factories: `User`, `Driver`, `Truck`, `DeliveryType`, `Route`, `RouteStop`.
