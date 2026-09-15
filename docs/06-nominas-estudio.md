# Nóminas (payroll) — estudio legal y de viabilidad (2026-09-14)

> **Estado: solo estudio, sin empezar.** El usuario pidió valorar si añadir generación de nóminas
> aprovechando los datos de fichaje ya existentes (Bloque 18); esto queda anotado para decidir más
> adelante si se implementa. No hay código ni migraciones de esta feature todavía.

## Planteamiento del usuario

El sistema de fichaje (Bloque 18, hoy desactivado en producción vía `ATTENDANCE_ENABLED=false`)
ya calcula horas ordinarias/extraordinarias por semana ISO
(`AttendanceStatsService::dailyBreakdownWithHours()`) para cada `User` que ficha. La idea: esas
horas alimentarían automáticamente una nómina; todo lo que la plataforma no rastrea hoy (salario
base, complementos, cotizaciones, IRPF, deducciones, vacaciones...) lo introduciría oficina a mano.

## Investigación legal (resumen, con fuentes)

- **Formato oficial del recibo de salarios**: Orden ESS/2098/2014 (modifica la Orden de 1994),
  [BOE-A-2014-11637](https://www.boe.es/buscar/doc.php?id=BOE-A-2014-11637). No existe obligación
  de software homologado para generarlo (a diferencia del futuro Veri-Factu de facturación) — un
  PDF bien formateado con esta estructura es legalmente válido como recibo. Estructura completa:

  1. **Encabezamiento** — empresa (nombre/razón social, domicilio, centro de trabajo si difiere,
     CIF, Código de Cuenta de Cotización/CCC) + trabajador (nombre, NIF, número de afiliación a la
     Seguridad Social, grupo profesional, grupo de cotización).
  2. **Período de liquidación** — días trabajados del mes, festivos incluidos.
  3. **Devengos** — (A) percepciones salariales: salario base + complementos salariales
     (antigüedad, nocturnidad, peligrosidad, zona geográfica... **todo dependiente del convenio
     colectivo aplicable**, no hay lista fija); (B) percepciones no salariales: dietas, plus de
     distancia/transporte, indemnizaciones. Subtotal: Total Devengado.
  4. **Deducciones** — (A) aportaciones del trabajador a la SS: contingencias comunes, desempleo,
     formación profesional (estas SÍ son una lista fija a nivel estatal); (B) IRPF: base sujeta,
     tipo, importe; (C) otras deducciones: anticipos, especie, cuota sindical. Subtotal: Total a
     Deducir.
  5. **Bases de cotización y cuadro de aportaciones** — desde la modificación de 2014, debe
     mostrar el importe tanto de la aportación del **trabajador** como de la **empresa** para cada
     concepto (contingencias comunes, AT-EP, desempleo, formación profesional, FOGASA — este
     último 100% empresa).
  6. **Totales** — Total Devengado, Total a Deducir, Líquido Total a Percibir.
  7. **Firma** — firma del trabajador o, si se paga por transferencia (lo habitual), el propio
     justificante bancario sirve como comprobante.

- **Conservación**: art. 30 Estatuto de los Trabajadores + art. 21.1 LISOS → **mínimo 4 años**,
  el mismo plazo ya aplicado a `attendances`/`attendance_corrections` en este proyecto.

- **Límite duro e ineludible**: la cotización real a la Seguridad Social (altas/bajas, TC2,
  partes de IT) solo se puede presentar por el **Sistema RED** (SILTRA/WinSuite32), y exige un
  representante RED autorizado con certificado digital — hoy, la gestoría. **Ninguna app propia
  puede sustituir ni tocar ese canal.** Esta es la razón principal por la que "generar la nómina"
  y "pagar/cotizar de verdad" son dos cosas separadas: la gestoría seguiría siendo imprescindible
  para lo segundo pase lo que pase con esta feature.

- **Cálculo de cotizaciones e IRPF**: las bases/tipos de cotización cambian cada año (tope máximo
  2026: 5.101,20 €/mes) y la retención de IRPF depende del algoritmo oficial de la AEAT +
  circunstancias personales de cada trabajador (hijos, discapacidad...). Construir un motor de
  cálculo propio sería alto riesgo económico/legal para una ganancia dudosa, dado que la gestoría
  de todas formas tiene que presentar la cotización real por Sistema RED con sus propios números.

- **Complementos salariales**: varían mucho por convenio colectivo/provincia (p. ej. nocturnidad
  15-25% según el convenio de transporte de mercancías consultado, plus por mercancías
  peligrosas, dietas de 5-8 €...). No existe un catálogo aplicable a cualquier empresa — cualquier
  diseño tiene que dejar esto como texto libre, nunca hardcodeado.

## Alcance recomendado, si se decide construir (decisiones ya conversadas con el usuario)

1. **"Solo registro y documento"**: oficina introduce TODOS los importes finales (devengos,
   deducciones, cotización SS lado trabajador y empresa, IRPF). La app solo hace aritmética básica
   (sumas → subtotales, líquido = devengado − deducido). **Nunca** calcula qué tipo/base
   *debería* aplicarse. Las horas de fichaje se muestran como referencia informativa, nunca
   alimentan un importe automáticamente.
2. **El PDF generado sería el recibo oficial** (sustituye al que hoy emite la gestoría) — debe
   reproducir fielmente la estructura de la Orden ESS/2098/2014 de arriba, no un resumen. La
   gestoría seguiría siendo imprescindible solo para la presentación real a la Seguridad Social.
3. **Permisos**: reutilizar `attendance.manage` (administrador + mantenimiento), sin permiso
   nuevo. Cada trabajador vería/descargaría solo sus propias nóminas, sin necesitar ese permiso —
   mismo principio que ya se aplica al acceso del trabajador a su propio fichaje en `/fichar`.

## Diseño técnico esbozado (para retomar si se aprueba)

- **Datos maestros nuevos en `users`** (mismo patrón que `dni`/`weekly_contracted_hours`, nunca en
  `Driver`): número de afiliación SS, categoría profesional (texto libre), grupo de cotización
  (1-11, lista SS cerrada de verdad), tipo de contrato (texto libre), IBAN (solo referencia).
- **Tabla `payrolls`** — una fila por nómina generada. Encabezamiento del empleado como snapshot
  congelado (JSONB, igual que `delivery_notes.customer_snapshot`); datos de empresa leídos en vivo
  de `config('servalillo.company.*')` (ampliar con `address`/`ccc`). Devengos/percepciones no
  salariales/otras deducciones como listas JSONB de texto libre `{label, amount}` (mismo patrón ya
  validado en este proyecto para "esto depende del caso, no lo hardcodees":
  `DeliveryType.field_schema`/`RouteStop.data`). Cotización SS como JSONB pero con **conceptos
  cerrados** (los 5 conceptos estatales fijos), con base/tipo/importe de trabajador y empresa.
  IRPF como columnas explícitas (una sola fila). Totales siempre recalculados en servidor.
- **Inmutabilidad**: `status` (`draft`/`issued`/`voided`) en vez de `SoftDeletes` — un recibo ya
  emitido es un documento legal del pasado (mismo criterio que `DeliveryNote`, que tampoco usa
  `SoftDeletes`). Editar/borrar solo en `draft`; corregir un `issued` = anular (motivo obligatorio,
  se conserva siempre) + generar un `draft` nuevo enlazado.
- **Capa de servicio**: `PayrollService` (crear/editar borrador, emitir con PDF, anular,
  regenerar) + `PayrollPdfRenderer` (mismo patrón dompdf/Helvetica que `pdf/delivery-note.blade.php`
  / `pdf/attendance-report.blade.php`, guardado en disco `r2`) — justificado igual que
  `DeliveryNoteService`/`AttendanceExportService`, no es un CRUD simple.
- **UI**: 3ª pestaña "Nóminas" dentro de `<x-attendance.tabs>` (mismo grupo de rutas "Gestión" que
  `fichajes/gestion`/`fichajes/totales`), modal ancho con scroll contenido (muchos campos). Vista
  "mis nóminas" para el propio trabajador, sin gate de permiso, solo nóminas propias en estado
  `issued`.
- **Retención/auditoría**: `payrolls` exenta de purga (igual que `attendances`), añadir a
  `Audits::MODELS`.

## No-objetivos explícitos (para no perder el alcance si se retoma esto)

- Nada de cálculo automático de cotización SS ni de retención IRPF.
- Nada de integración con Sistema RED/SILTRA/TGSS — la gestoría sigue presentando la cotización
  real; esta app nunca debe describirse como un sustituto de ese canal.
- Nada de gestión de vacaciones/bajas/ausencias — no existe hoy, no se añadiría con esta feature.
- Nada de conceptos salariales hardcodeados por convenio — siempre texto libre salvo los 5
  conceptos de cotización SS (estatales, no dependen de convenio).

## Fuentes citadas

- [Orden ESS/2098/2014 — BOE-A-2014-11637](https://www.boe.es/buscar/doc.php?id=BOE-A-2014-11637)
- [Estructura del recibo de salarios — Tiempos Modernos](https://www.tiemposmodernos.eu/nomina-estructura-recibo-de-salarios/)
- [Cómo calcular una nómina 2026 — España Laboral](https://espanalaboral.es/guias-y-recursos/trabajadores/como-calcular-nomina/)
- [Sistema RED — Info-trámites](https://info-tramites.es/sistema-red/) y [Holded](https://www.holded.com/es/blog/sistema-red)
- [Plazos de conservación de documentación — Nalanda Global](https://www.nalandaglobal.com/blog/cuantos-anos-se-debe-guardar-la-documentacion-en-una-empresa/)
- Convenios colectivos de transporte de mercancías por carretera 2026 (Córdoba, Murcia, Valencia —
  consultados solo como ejemplo de variabilidad de complementos, no como fuente normativa fija)
