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

## Dispositivos y protección del terminal

En **Dispositivos** (menú *Datos*) están los móviles de los chofers con la app de seguimiento (tracker) instalada, uno por tarjeta. Cada móvil aparece solo la primera vez que se abre la app. En cada tarjeta **asignas el chofer** con el desplegable (un chofer solo puede tener un móvil) y, en el botón **Acciones**:

- **Seguridad**: la protección del terminal (ver abajo).
- **Localizar**: el mapa con su última posición y el recorrido reciente.
- **Modo perdido (robo o pérdida)**: bloquea el móvil (ver abajo).
- **Desactivar / Reactivar seguimiento**: detiene o reanuda el envío de posiciones. Un móvil desactivado tampoco recibe cambios de protección.
- **Eliminar**: borra el móvil y todas sus posiciones (por ejemplo, al retirarlo, después de liberarlo).
- **Dar de alta un móvil** (arriba a la derecha) muestra el código QR para preparar un móvil nuevo (ver abajo).
- **La app se actualiza sola.** Debajo del botón ves la versión publicada. Los móviles dados de alta con el código QR comprueban cada pocos minutos si hay una versión nueva, la descargan y se instalan solos, sin preguntar ni perder nada; la columna *App* de cada móvil muestra la versión que tiene. El enlace **Descargar APK para instalarla a mano** solo hace falta si el servicio técnico tiene que dar de alta un móvil con el ordenador. No compartas la app fuera de la empresa: lleva dentro el acceso al servidor.

La columna **Protección** resume el estado de cada móvil: **Protegido** (aplica lo que has pedido), **Pendiente** (aún no lo ha confirmado; los móviles se sincronizan cada pocos minutos), **Sin administración** (el móvil no ha pasado el alta del servicio técnico y no puede aplicar nada), **Sin proteger** o **Liberado**.

### Protección del terminal

Con **Seguridad** decides qué no puede hacer el chofer en ese móvil. Las restricciones afectan a **todo el móvil**, no solo a la app:

- **Bloquear capturas de pantalla**: ni capturas ni grabaciones de pantalla. Al intentarlo, el móvil avisa de que no se permite por la política de seguridad.
- **Impedir desinstalar la app** de seguimiento.
- **Impedir restablecer de fábrica** desde los Ajustes del móvil.
- **Impedir el modo seguro** (arrancar el móvil sin apps de terceros).

También pones la **clave del terminal** (de 4 a 8 números). La app la pide para abrirse en el móvil y, ya dentro, permite al técnico **quitar las restricciones 30 minutos** o **liberar el terminal**, aunque no haya internet. Tras 5 intentos fallidos la app se bloquea 5 minutos. Si el móvil ya tiene clave, deja el campo en blanco para mantenerla. La clave no se guarda en ningún sitio en claro: si se olvida, pon una nueva desde aquí.

Al **Guardar**, el móvil lo aplica en unos minutos y la ventana te muestra, debajo de cada opción, si ya está **aplicado en el móvil**.

- **Aplicar a todos**: copia las restricciones marcadas a todos los móviles activos (la clave de cada uno no cambia).
- **Liberar terminal**: quita todas las restricciones y la app deja de administrar el móvil. Hazlo **siempre antes de retirar, vender o reutilizar un móvil**, porque con la protección puesta no se puede restablecer desde los Ajustes. Para volver a protegerlo, el servicio técnico tendrá que repetir el alta.

> Importante: para poder proteger un móvil hay que darlo de alta antes con el **código QR** (una sola vez por móvil, ver abajo). Un móvil que solo tiene la app instalada a mano aparece como **Sin administración** y no se le puede bloquear nada. La protección solo funciona en móviles Android.

### Modo perdido (robo o pérdida)

Si avisan de que un móvil se ha perdido o lo han robado, abre **Acciones → Modo perdido**, revisa el mensaje y pulsa **Activar modo perdido**:

- **En el acto** se cierran todas las sesiones de la web del chofer de ese móvil: para volver a entrar hará falta su contraseña. Esto protege la información de la empresa aunque el móvil no tenga conexión. El chofer puede entrar normalmente desde otro móvil.
- **En cuanto el móvil tenga conexión** (lo comprueba cada 5 minutos) se bloquea la pantalla y aparece tu mensaje en la pantalla de bloqueo; para usarlo hace falta el PIN. Mientras siga perdido se vuelve a bloquear en cada comprobación.
- **Sigue enviando su posición**, también de noche: usa **Localizar** para verlo en el mapa.

La tarjeta muestra **Perdido · pendiente de bloquear** hasta que el móvil confirma el bloqueo, y **Perdido · bloqueado** después. Si el móvil está apagado o sin cobertura, el bloqueo se aplica cuando vuelva a conectarse. Cuando aparezca, **Acciones → Desactivar modo perdido**. Solo funciona en móviles dados de alta con el código QR; en los demás solo se cierran las sesiones de la web.

### Dar de alta un móvil (código QR)

Sirve para un móvil **nuevo o recién restablecido de fábrica**: el propio móvil descarga la app, la instala y la deja preparada para protegerlo, sin cables ni ordenador.

1. En **Dispositivos**, pulsa **Dar de alta un móvil**. Aparece un código QR. Si quieres que el móvil se conecte solo a la Wi-Fi, escribe su nombre y contraseña debajo (no se guardan, solo van dentro del código).
2. Restablece el móvil de fábrica (o enciéndelo si es nuevo) y espera a la **pantalla de bienvenida**.
3. **Toca 6 veces seguidas** en un mismo punto vacío de esa pantalla: se abre el lector de códigos QR.
4. Si lo pide, conéctalo a una Wi-Fi y **escanea el código** de la pantalla del ordenador.
5. Acepta los avisos de que el **dispositivo lo gestiona tu organización**. El móvil descarga e instala la app (tarda unos minutos).
6. Termina de configurar el móvil como siempre, abre **Servalillo Tracker** y concede los permisos que pide.
7. El móvil aparece en **Dispositivos**: asígnale el chofer y, en **Seguridad**, pon la clave y marca las restricciones.

El código vale **24 horas**; después, vuelve a pulsar **Dar de alta un móvil** para generar otro. No lo compartas fuera de la empresa: permite descargar la app. Si al tocar 6 veces no aparece el lector, ese modelo de móvil no admite el alta por QR: avisa al servicio técnico, que puede hacerla con el ordenador.

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
