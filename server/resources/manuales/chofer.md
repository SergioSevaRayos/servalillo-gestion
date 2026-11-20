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
- **Cómo llegar**: abre Google Maps hasta su puerta. Si el cliente aún no tiene ubicación guardada, verás en su lugar **Capturar ubicación**: púlsalo cuando estés en el sitio y se guardará con el GPS del móvil para las próximas veces.
- **Pedido**: lo que tiene previsto este cliente.

Después elige **cómo ha ido**: **Entregada**, **Fallida** o **Cancelada**. El formulario cambia según tu elección.

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

## Preguntas frecuentes

**No me deja tocar las paradas.** Comprueba que has empezado la jornada y que estás viendo el día de hoy.

**He quitado una parada sin querer.** Avisa a la oficina: pueden volver a colocarla en tu ruta.

**Quiero volver a ver la guía.** Pulsa el botón **?**: junto a «Hoy» para la pantalla de la ruta, y dentro de una parada para el formulario de cierre.
