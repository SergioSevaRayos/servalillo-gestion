# Manual de administración

Guía de las pantallas de gestión: planificar rutas, controlar el reparto del día, atender albaranes y facturas y vigilar la flota. El menú lateral (en móvil, el botón de la gota) agrupa las pantallas en **Operación**, **Datos** y **Sistema**; la campana avisa de lo que hacen los choferes (cierres de parada, facturas pedidas, incidencias…).

## Panel estadístico

Resumen de actividad con selector de periodo (7 días, 30 días, 90 días, año): rutas activas hoy, entregas completadas, tasa de éxito, litros entregados, cumplimiento y paradas sin asignar, más gráficas de paradas por día, estado de las rutas, litros por tipo de reparto y entregas por chofer.

![Panel estadístico](/images/manual/administracion/01-panel.png)

## Rutas: el tablero del día

**Rutas** es la pantalla principal de operación. Cada **columna es una ruta del día** (camión + chofer) y cada **tarjeta es una parada**. A la izquierda está la columna fija **Sin asignar**, con las paradas que todavía no tienen ruta.

![Tablero de rutas](/images/manual/administracion/02-rutas-tablero.png)

- **Cambiar de día**: con la fecha y las flechas de arriba. «Hoy» vuelve al día actual. El interruptor **Repartos / Viajes** filtra por tipo de servicio.
- **Mover paradas**: arrastra una tarjeta a otra columna (o a otra posición) para reasignarla o reordenarla. Solo se pueden mover las paradas **pendientes**.
- **Añadir parada**: «+ Añadir parada» crea una parada manual en esa ruta; **Buscar cliente** en «Sin asignar» te deja meter un cliente existente.
- **Cabecera de ruta**: muestra camión, chofer, estado y nº de paradas. Pulsa el estado para cambiarlo a mano (por ejemplo, reabrir una ruta que el chofer cerró por error).
- **Ver recorrido**: mapa con las paradas y, si hay GPS, el recorrido real.
- **Ruta eficiente**: reordena las paradas pendientes por el camino más corto.
- El tablero **se refresca solo** cada pocos segundos; lo que hace el chofer aparece sin recargar.

> Las paradas de clientes con calendario semanal se generan solas cada día; si hay una única ruta posible nacen ya colocadas, y si no, aparecen en «Sin asignar» para que las coloques.

### Listado de rutas permanentes

El enlace **Gestionar rutas** de la esquina del tablero (o `/rutas/listado`) es la ficha **permanente**: qué camión y qué chofer hacen cada ruta y desde cuándo. De ella salen solas las rutas de cada día. Desde aquí también puedes abrir el **Historial** de cada ruta (días, estado, litros repartidos y contador).

![Listado de rutas permanentes](/images/manual/administracion/03-rutas-listado.png)

> Regla: un camión (y un chofer) solo puede tener **una** ruta permanente vigente a la vez.

## Clientes

Listado con búsqueda, filtros y orden. Aquí das de alta clientes y pre-clientes (valoración), con su dirección, ubicación, cantidad habitual, tarifa y **calendario de reparto**: bajo demanda, días de la semana (con fechas de inicio y fin opcionales) o cada N días.

![Clientes](/images/manual/administracion/04-clientes.png)

En la **ficha del cliente** ves su historial y su mapa, y tienes dos acciones clave:

- **Planificar reparto**: genera de golpe las paradas de un rango de fechas en los días de la semana que elijas, en la ruta que indiques o en «Sin asignar».
- **Suspender repartos**: cuando el cliente deja el servicio, quita su calendario y sus paradas pendientes de una vez, en lugar de borrarlas una a una.

![Ficha de cliente](/images/manual/administracion/14-cliente-ficha.png)

## Albaranes

Cada parada entregada genera su albarán en PDF. Aquí los ves todos, con filtros y su estado, y puedes abrir el PDF. Si uno no se generó o no se envió bien, usa **Reprocesar**. Los albaranes «por email» se envían al cliente con la firma recogida por el chofer; los de «entrega en mano» solo quedan registrados y se pueden **Marcar entregado**.

![Albaranes](/images/manual/administracion/05-albaranes.png)

## Facturas

La factura se hace fuera de la aplicación; aquí se controla **qué falta por hacer**. Cuando el chofer marca «El cliente quiere factura» (o el cliente está marcado como «siempre factura») aparece una factura **pendiente** con los datos del cliente tal y como estaban en la entrega.

![Facturas](/images/manual/administracion/06-facturas.png)

1. Filtra por **Pendiente** (es el filtro por defecto; el menú muestra cuántas hay).
2. Haz la factura en tu programa y vuelve aquí a **Marcar emitida**, indicando el **número de factura** (obligatorio).
3. Cuando se la envíes al cliente, **Marcar enviada**.

## Avisos

Reglas que vigilan la flota y avisan cuando algo se sale de lo normal: silencio del GPS, batería baja, jornada sin empezar o sin cerrar y exceso de velocidad. Cada regla tiene su interruptor y su umbral (minutos, porcentaje, hora o km/h) y se puede aplicar a todos los dispositivos o solo a algunos. La pantalla se evalúa y se actualiza sola; los avisos activos aparecen en el menú con una insignia roja, y los resueltos quedan en un **historial** con filtros que se purga solo pasados 30 días.

![Avisos](/images/manual/administracion/07-avisos.png)

## Mapa de la flota

Última posición de cada camión con su chofer y el estado de su ruta de hoy. Se actualiza cada 30 segundos sin perder el zoom. Los estados son **en línea**, **sin señal** (lleva más tiempo del umbral del aviso de silencio) y **sin datos** (nunca ha enviado posición).

![Mapa de la flota](/images/manual/administracion/08-mapa.png)

## Chofers, camiones y usuarios

- **Chofers**: alta de choferes (se crea a la vez su usuario de acceso), con acceso al **diario** de incidencias de cada uno.
- **Camiones**: matrícula, código y lectura del contador de litros.
- **Usuarios**: personal de gestión (administradores y mantenimiento). Los choferes **no** se gestionan aquí, solo desde «Chofers».

![Chofers](/images/manual/administracion/09-chofers.png)

![Camiones](/images/manual/administracion/10-camiones.png)

![Usuarios](/images/manual/administracion/11-usuarios.png)

## Depósitos

Consulta de los depósitos de los clientes desde el sistema SGRA.

![Depósitos](/images/manual/administracion/13-depositos.png)

## Soporte / incidencias

Si algo no funciona, abre una incidencia desde aquí; llega al equipo de mantenimiento, que puede responderte y cerrarla.

![Soporte](/images/manual/administracion/12-soporte.png)

## Consejos

- **Tema claro/oscuro**: cámbialo desde tu nombre (abajo del menú lateral).
- **Manual del chofer**: arriba de esta página tienes también el manual que ven los choferes, útil para formarles.
- **Campana**: cada aviso te lleva a la pantalla afectada.
