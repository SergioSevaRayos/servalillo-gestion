# Sistema de diseño (Bloque 2)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

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
- **Bug real de producción corregido (2026-09-23), sistémico: ningún campo de ningún formulario
  Livewire de la app pintaba de verdad su error de validación (ni el borde rojo ni el mensaje).**
  Descubierto al pedir el usuario el aviso de formato de coordenadas (ver Bloque 9) y comprobar
  que, pese a que `assertHasErrors()` daba positivo en los tests, el HTML renderizado nunca
  contenía el mensaje (confirmado con un test que inspecciona `$test->html()`, no solo el error
  bag). Causa: `Livewire\Form::validate()` guarda cada error con el **nombre completo de la
  propiedad** (`"form.latitude"`, `"form.email"`…, prefijado con el nombre de la propiedad del
  Form en el componente — `Arr::prependKeysWith($raw, $this->getPropertyName().'.')`), pero
  `components/ui/{input,select,textarea,password-input,date-input}.blade.php` buscaban
  `$errors->first($name)` con el **`name` plano del campo** (`"latitude"`, `"email"`…, el prop que
  fija el atributo HTML `name=`/`id=`) — `MessageBag::get()` hace comparación exacta de clave, así
  que nunca encontraba nada y `$errorMsg` quedaba siempre `null`. Afectaba a **todos** los
  formularios Livewire con `Form` object del panel (clientes, usuarios, chofers, camiones, rutas…)
  desde que existen — invisible porque cada test de validación solo comprobaba
  `assertHasErrors('form.campo')` (el error bag), nunca si el HTML lo mostraba. **Fix**: cada uno
  de los 5 componentes calcula ahora la clave real a partir del atributo `wire:model`/`wire:model
  .blur`/`.live` recibido (macro `$attributes->wire('model')->value()`, ya provista por Livewire —
  `date-input` ya la usaba para su lógica interna pero no para el error; los otros 4 no la usaban
  en absoluto), con `$name` como fallback solo si no hay ningún `wire:model*` (p. ej. los inputs de
  demostración de `/style-guide`, sin binding real). Regresión cubierta con tests que además de
  `assertHasErrors()` comprueban que el HTML contiene el mensaje traducido y `border-rose-400`
  (`ClientsCrudTest`, `UsersCrudTest`). **Si se crea un componente de campo nuevo (`ui/*`), replica
  este patrón desde el principio** — es fácil volver a introducir el mismo bug copiando
  `$errors->first($name)` sin pensarlo.
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
  **Bug real de producción corregido (2026-09-23): "no puedo crear un usuario".** `lang/es/
  validation.php` traducía `attributes.password` ("contraseña", el NOMBRE del campo) pero le
  faltaba el array `password` de nivel superior (`letters`/`mixed`/`numbers`/`symbols`/
  `uncompromised` — los mensajes de las propias reglas del `Password` rule, una clave
  totalmente distinta). `AppServiceProvider::boot()` aplica `Password::defaults()->
  uncompromised()` **solo en producción** — esa regla llama a la API de HaveIBeenPwned y
  rechaza cualquier contraseña que haya aparecido en una filtración conocida (muy probable con
  contraseñas de prueba tipo "Password123"). Con la clave sin traducir, Laravel muestra la
  clave en crudo (`validation.password.uncompromised`) bajo el campo — indistinguible de "no
  hace nada" para un administrador sin conocimientos técnicos: el formulario SÍ validaba (mal)
  y SÍ mostraba un error, solo que ilegible. Consecuencia real: **0 usuarios nuevos creados
  desde el alta inicial** (confirmado en producción — solo existían los 3 de la puesta en
  marcha). Como el fallo no lanza ninguna excepción (es una validación que falla
  correctamente), no dejaba rastro en `error_logs` ni en los logs de Laravel — se diagnosticó
  comparando el conteo de usuarios/`created_at` en producción, no por logs. Fix: añadidas las
  5 claves en español. Test de regresión:
  `tests/Feature/PasswordValidationMessagesTest.php` (fuerza un `UncompromisedVerifier` falso
  para no depender de red).
- `resources/views/vendor/pagination/tailwind.blade.php`: vista de paginación por defecto de Laravel,
  publicada y restilizada con los tokens del proyecto (se detecta por convención, no requiere registro).

