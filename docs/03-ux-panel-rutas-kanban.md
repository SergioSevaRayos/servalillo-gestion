# Apunte de UX — panel de rutas estilo Trello (a aplicar en Bloques 3 y 4)

> Estado: **dirección de diseño y decisiones validadas por el usuario**, pendiente de construir.
> Fecha: 2026-09-05

## La idea

El panel de gestión de rutas del Administrador/Mantenimiento se plantea como un **tablero tipo
Trello**, no como una tabla plana:

- **Columnas = rutas** del día seleccionado. Cada columna se identifica por su camión + chofer
  (ej. "C-01 · Pedro Ramírez").
- **Tarjetas dentro de cada columna = paradas/repartos** (`route_stops`) de esa ruta.
- En el perfil del **chofer**, el mismo patrón pero simplificado: **una sola columna** = su ruta de
  hoy, con las paradas como tarjetas.

## Cómo encaja con lo ya construido

- El modelo de datos ya lo soporta sin cambios: `route_stops.position` (orden dentro de la columna) y
  `route_stops.route_id` (a qué columna pertenece) son exactamente los dos campos que un tablero Kanban
  necesita mover.
- El Bloque 4 ("drag & drop de paradas") deja de ser solo *reordenar dentro de una ruta* y pasa a ser
  también *mover una parada de una columna (ruta) a otra* — reasignar un reparto de un camión/chofer a
  otro arrastrando la tarjeta. SortableJS soporta esto de fábrica con listas conectadas
  (opción `group`), emitiendo la ruta origen, ruta destino y nueva posición a un método Livewire.
- El sistema de diseño del Bloque 2 ya tiene las piezas: `<x-ui.badge>` con `RouteStopStatus::badgeVariant()`
  para el estado de cada tarjeta, `.glass` para la cabecera de cada columna (es "chrome"), `.surface`
  para el cuerpo de las tarjetas (contenido denso, debe ser legible).

## Decisiones ya validadas por el usuario (2026-09-05)

1. **Selector de fecha**: sí — por defecto "hoy", con selector para ver/planificar otros días.
2. **Columna "Sin asignar"**: sí, existe como columna fija del tablero. Las paradas pueden crearse sin
   ruta/camión asignado todavía y viven ahí hasta que un admin las arrastra a la columna de una ruta.
3. **Alcance del CRUD tradicional**: confirmado — los listados normales (chofers, camiones, histórico,
   albaranes) siguen siendo tablas con búsqueda/filtro/orden/paginación. El tablero Kanban es solo la
   pantalla de planificación/gestión de rutas del día.
4. **Móvil**: **una columna visible a la vez, con swipe** entre columnas (no apiladas verticalmente).
   Encaja con el patrón "tabla → tarjetas" del Bloque 2: en móvil ya pensamos en una unidad de contenido
   a la vez; aquí la unidad es la columna completa, no la fila.

### Implicaciones para la construcción (Bloque 3/4)

- La columna "Sin asignar" es una pseudo-columna: no corresponde a una `Route`, así que sus tarjetas son
  `route_stops` con `route_id` nulo — **hay que permitir `route_stops.route_id` nullable** (ahora mismo
  la migración lo exige `NOT NULL`/`constrained()`). Revisar esto al construir el Bloque 3: probablemente
  una migración que relaje esa columna, o repensar si "sin asignar" vive en otra tabla ligera. A decidir
  en el momento de construirlo, no antes.
- Swipe en móvil sugiere un carrusel de columnas con scroll-snap (`snap-x snap-mandatory` de Tailwind +
  `overflow-x-auto`) en vez de SortableJS multi-columna visible a la vez; el drag&drop entre columnas en
  móvil probablemente necesite una interacción distinta al arrastre de escritorio (p. ej. un menú de
  "mover a…" en la tarjeta, ya que arrastrar-y-hacer-swipe-de-columna a la vez es difícil con el dedo).
  Diseñar esto con calma en el Bloque 4, probando en un móvil real antes de darlo por bueno.

## Chofer

Su vista dejará de ser el placeholder plano actual: una única columna con sus paradas de hoy como
tarjetas, cada una con acción para marcar en curso/completada, firma y contador — el Bloque 7 diseña
el detalle, pero la lista base ya es "una columna de tarjetas", no una tabla.
