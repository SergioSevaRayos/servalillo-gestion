# Manual del chofer

Esta guía explica cómo usar la aplicación en el móvil durante tu jornada: ver tu ruta, empezar y terminar la jornada y cerrar cada parada. Si en algún momento te pierdes, pulsa el botón **?** que aparece junto a «Hoy» (y dentro de cada parada): te lanza una guía paso a paso sobre la pantalla en la que estás.

## Tu ruta del día

Al entrar ves **Mi ruta**. Arriba tienes el día (por defecto, hoy) y debajo la tarjeta de tu camión con el estado de la ruta, cuántas paradas llevas cerradas y el contador de litros.

![Mi ruta antes de empezar la jornada](/images/manual/chofer/01-mi-ruta-sin-empezar.png)

- **Selector de día**: desliza o toca un día para consultar la ruta de otro día. «Hoy» te devuelve al día actual. Solo puedes trabajar sobre la ruta de hoy; el resto de días son de consulta.
- Si no aparece ninguna ruta, es que la oficina todavía no te ha asignado una para ese día. Aparecerá sola en cuanto la asignen.
- La pantalla **se actualiza sola**: si la oficina cambia algo de tu ruta, lo verás sin recargar. Además recibirás un aviso en la campana.

## Empezar la jornada

Hasta que no empieces la jornada **no puedes operar las paradas** (las tarjetas salen apagadas).

1. Pulsa **Empezar jornada**.
2. Anota la lectura del **contador de litros** del camión (ya viene rellenada con la última lectura registrada; corrígela si el contador marca otra cosa).
3. Pulsa **Empezar**.

![Lectura del contador al empezar](/images/manual/chofer/02-empezar-jornada.png)

> La lectura no puede ser menor que la última registrada del camión. Si te da error, revisa que no te hayas equivocado de cifra.

## Durante la jornada

![Mi ruta con la jornada en curso](/images/manual/chofer/03-mi-ruta-en-curso.png)

- **Contador de litros**: muestra la lectura con la que empezaste y por dónde «debería ir» el contador según lo repartido.
- **Seguir ruta en Google Maps**: abre Google Maps con todas tus paradas pendientes, en el orden actual, usando tu posición como salida.
- **Ver recorrido**: muestra la ruta y las paradas en un mapa dentro de la aplicación.
- **Organización automática**: reordena las paradas pendientes por el camino más eficiente. Te pregunta desde dónde sale el camión (**desde la base** o **desde un cliente**, una de tus paradas pendientes) y reordena el resto desde ahí. Solo aparece si tienes más de una parada pendiente.
- **Gestionar**: desbloquea las paradas para poder **subirlas o bajarlas** de orden (▲ / ▼) o **quitarlas** de tu ruta. Por defecto están bloqueadas para que no las muevas sin querer; cuando termines pulsa **Listo**.
- El icono de **chincheta** junto a cada parada abre Google Maps hacia esa parada concreta.

### Añadir un cliente que ha llamado

Si un cliente te llama, pulsa **Añadir cliente (ha llamado)**, busca su nombre y se añade como parada nueva al final de tu ruta.

![Añadir un cliente a la ruta](/images/manual/chofer/08-anadir-cliente.png)

## Cerrar una parada

Toca la tarjeta de una parada para abrirla. Arriba ves los datos del cliente:

- **Teléfono**: tócalo para llamarle.
- **Cómo llegar**: abre Google Maps hasta su puerta. Si el cliente aún no tiene ubicación guardada, verás en su lugar **Capturar ubicación** (ver *Guardar la ubicación de un cliente*, justo debajo).
- **Pedido**: lo que tiene previsto este cliente.

Después elige **cómo ha ido**: **Entregada**, **Fallida** o **Cancelada**. El formulario cambia según tu elección.

### Guardar la ubicación de un cliente

Si un cliente no tiene la ubicación guardada, al abrir su parada no aparece **Cómo llegar**, sino el botón **Capturar ubicación**. Guárdala tú cuando estés allí: así la oficina lo ve en el mapa, la ruta se puede ordenar mejor y la próxima vez tendrás **Cómo llegar**.

1. **Ve hasta el punto de entrega** (la puerta o el depósito donde descargas), no lo hagas desde lejos ni antes de llegar: se guarda el sitio donde está el móvil en ese momento.
2. Abre la parada y pulsa **Capturar ubicación**.
3. La primera vez, Chrome pregunta si la página puede usar tu ubicación: pulsa **Permitir**.
4. Espera unos segundos mientras pone **Capturando…**. Al terminar verás **Ubicación guardada** y el botón cambia a **Cómo llegar**.

La ubicación se guarda en esa parada y, si la ficha del cliente no tenía ninguna, también en la ficha, para las próximas entregas. **No se puede cambiar desde el móvil**: si te equivocas o ves que la ubicación de un cliente está mal, avisa a la oficina para que la corrijan.

Si sale un error:

- **«No se pudo obtener la ubicación»**: comprueba que la ubicación del móvil está activada (desliza el menú de arriba y toca *Ubicación*) y vuelve a pulsar. Al aire libre funciona mejor que dentro de una nave.
- **Si pulsaste «Bloquear» cuando Chrome preguntó**: en Chrome, toca el icono de la izquierda de la dirección de la página → **Permisos** → **Ubicación** → **Permitir**, y vuelve a intentarlo.

### Entregada

![Parada entregada con albarán por email](/images/manual/chofer/04-parada-entregada-email.png)

1. **Cómo se entrega el albarán**
   - **Enviar por email**: el cliente firma con el dedo en tu pantalla y se le envía el albarán por correo. Tendrás que rellenar el **email del cliente**, el **nombre de quien firma** y la **firma**.
   - **Entrega en mano**: le das la copia impresa y firma el papel. En la aplicación solo puedes anotar, si quieres, quién lo recibe.
2. **Litros entregados**: los litros realmente descargados (viene rellenado con lo pedido; cámbialo si ha sido otra cantidad).
3. **DNI / CIF del cliente**: si el cliente ya lo tenía guardado sale solo; si no, escríbelo y se guarda para la próxima vez.
4. **El cliente quiere factura**: márcalo si la pide. Administración la prepara y se la envía. En ese caso el DNI/CIF es obligatorio.
5. **Contador de litros**: déjalo en **Pasa por el contador**. Pulsa el botón solo si esos litros **no** salen de la cisterna del camión (por ejemplo, un repostaje aparte): pasará a **No pasa por el contador** y no se descontarán al cuadrar la jornada.
6. **Datos del reparto**: campos propios del tipo de reparto (por ejemplo, tipo de depósito, agua potable u observaciones). Rellena los que correspondan.
7. Pulsa **Guardar**.

![Parada entregada con entrega en mano](/images/manual/chofer/05-parada-entregada-en-mano.png)

> Si la parada ya tiene albarán (por ejemplo, la reabriste), en lugar de elegir cómo entregarlo verás el número y el estado del albarán.

### Fallida

Úsala cuando **no has podido entregar** (cliente ausente, sin acceso, avería…).

![Parada fallida](/images/manual/chofer/06-parada-fallida.png)

1. Escribe el **motivo del fallo** (obligatorio: lo verá la oficina).
2. Opcional: **Reprogramar para otro día**. Elige una fecha futura y se crea una parada nueva ese día, dentro de tu ruta. Aparecerá marcada como «Reprogramada».
3. Pulsa **Guardar**.

### Cancelada

Úsala cuando **el cliente anula** el pedido.

![Parada cancelada](/images/manual/chofer/07-parada-cancelada.png)

Funciona igual que «Fallida»: escribe el **motivo de la cancelación** y, si hay que volver otro día, elige la fecha para reprogramar.

### Me he equivocado

Abre la parada otra vez y pulsa **Reabrir parada**: vuelve a quedar pendiente y puedes cerrarla de nuevo.

## Terminar la jornada

Cuando acabes todas las paradas, pulsa **Terminar jornada** y anota la lectura final del contador. La aplicación la compara con lo repartido: si no cuadra, te pedirá una nota explicando la diferencia. Al terminar verás el resumen (contador de inicio, de fin y litros repartidos).

> Si después de terminar la jornada te llama un cliente y lo añades, la jornada se **reabre** automáticamente.

## Si pierdes el móvil o te lo roban

El móvil de empresa da acceso a los datos de los clientes. Si lo pierdes o te lo roban, actúa enseguida:

1. **Avisa a la oficina cuanto antes**, desde otro teléfono. No esperes a ver si aparece: cuanto antes avises, antes queda protegido.
2. La oficina **bloquea el móvil desde la web**. En ese momento se cierra tu sesión de la aplicación en todos los dispositivos, y en cuanto el móvil tenga cobertura se bloquea la pantalla y aparece un mensaje para que quien lo encuentre llame a la empresa. El móvil sigue enviando su posición, así que la oficina puede ver dónde está.
3. **Para seguir trabajando**, entra en la aplicación desde otro móvil con tu usuario y contraseña de siempre. Si la oficina te ha cambiado la contraseña por seguridad, te dará la nueva.
4. **Si lo recuperas**, no intentes desbloquearlo ni restablecerlo: llévalo a la oficina o avisa para que lo desbloqueen desde la web.

Para que el móvil esté protegido si se pierde:

- **No le quites el PIN** de la pantalla de bloqueo ni lo compartas, y no lo apuntes en ningún sitio del propio móvil.
- **No lo dejes desbloqueado** y a la vista, por ejemplo en la cabina del camión.
- Si notas que alguien ha usado el móvil sin permiso, avisa también a la oficina.

## Preguntas frecuentes

**No me deja tocar las paradas.** Comprueba que has empezado la jornada y que estás viendo el día de hoy.

**He quitado una parada sin querer.** Avisa a la oficina: pueden volver a colocarla en tu ruta.

**Quiero volver a ver la guía.** Pulsa el botón **?**: junto a «Hoy» para la pantalla de la ruta, y dentro de una parada para el formulario de cierre.

**No me deja guardar la ubicación de un cliente.** Mira *Guardar la ubicación de un cliente*: casi siempre es que la ubicación del móvil está apagada o que Chrome no tiene permiso.

**No puedo hacer capturas de pantalla.** Es a propósito: el móvil de empresa no permite capturas ni grabaciones de pantalla, para proteger los datos de los clientes. Si necesitas enseñar algo a la oficina, explícaselo por teléfono o pídeles que lo miren desde la web.

**La app de seguimiento me pide una clave.** Es la clave del servicio técnico; no la necesitas para trabajar. La aplicación de reparto se usa desde Chrome, como siempre.
