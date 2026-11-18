# Diario de incidencias del chofer (Bloque 14)

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Diario de incidencias del chofer (Bloque 14, 2026-09-10)
- **`/chofers/{driver}/diario`** (`App\Livewire\Drivers\Diary`, enlazada desde la fila de cada
  chofer en `/chofers` con un botón **"Diario"**): notas de oficina sobre lo que hace un chofer,
  bueno o malo — no lo escribe el chofer, es para uso interno de administración/mantenimiento.
  Filtros: categoría, rango de fechas, búsqueda en el texto.
- **`App\Models\DriverLog`** (`driver_id`, `occurred_on`, `category`, `body`, `created_by`,
  `updated_by` nullable, `SoftDeletes`, `Auditable`). `App\Enums\DriverLogCategory` (positiva /
  negativa / neutra, con `->badgeVariant()`) para distinguir de un vistazo lo bueno de lo malo en
  el listado.
- **Quién y qué ha cambiado**: `updated_by` se queda `null` mientras nadie la ha tocado tras
  crearla (`DriverLog::wasEdited()`); `DriverLogForm::save()` lo rellena con el usuario actual
  **solo** al editar, nunca al crear. El listado muestra "Anotado por X el ..." siempre y, si se ha
  editado, además "Editado por Y el ..." + un enlace **"Ver cambios"** que abre un modal con el
  historial — como cualquier modelo `Auditable`, el rastro completo y sin filtrar (todos los
  campos, todos los eventos) también aparece en la Auditoría general (`Audits::MODELS` incluye
  `'Incidencia de chofer' => DriverLog::class`); este modal es una versión **curada** para oficina.
  - **`Diary::historyEntries()`** (no `Audit::getModified()` en crudo — probado en producción y
    era ilegible: mostraba el evento `created` entero con `id`/`driver_id`/`created_by` en
    `null → …`, y el valor de `category` como el objeto enum, no su etiqueta): filtra a
    `event = 'updated'` únicamente (el `created` ya lo resume la cabecera "Creada por…"),
    descarta cualquier campo fuera de `HISTORY_FIELDS` (`occurred_on`/`category`/`body` — nunca
    `id`/`driver_id`/`created_by`/`updated_by`/timestamps), y si tras filtrar un `updated` se
    queda sin campos relevantes (p. ej. un resave que solo tocó `updated_by`), esa tarjeta entera
    se descarta. `formatHistoryValue()` pinta cada valor en español: `category` → su `label()` (un
    valor de un campo con cast a enum nativo llega como **instancia** del enum, no como string —
    `getFormattedValue()` del paquete aplica los casts del modelo —, así que además de traducirlo
    hay que sacarlo de ahí explícitamente), `occurred_on` → `d/m/Y`, el resto → texto truncado;
    `null`/`''` → `'—'`.
  - **Gotcha ya resuelto**: la primera versión casteaba los valores con `(string) $valor` →
    *"Object of class App\Enums\DriverLogCategory could not be converted to string"* (los enums
    nativos no implementan `__toString()`) — 500 real en producción al pulsar "Ver cambios" sobre
    un cambio de `category`. `json_encode()` sí sabe serializar un backed enum (por eso el modal de
    `/mantenimiento/auditoria`, que ya usaba `json_encode`, nunca se topó con esto) — pero aquí se
    optó por lo anterior (filtrar + `formatHistoryValue()`) porque además de no reventar, es lo que
    pidió el usuario: "que se muestren datos relevantes, no variables ni nulls, solo lo relevante".
  - **No se puede probar en Pest** que el `Audit` se cree de verdad al editar — `config/audit.php`
    → `console => false` desactiva el auditing automático en cualquier ejecución por consola,
    tests incluidos (mismo límite que el resto de modelos `Auditable` de la app) — los tests de
    este modal insertan el `Audit` a mano (`Audit::create(...)`, mismo patrón que
    `MaintenancePanelTest::seedAudit()`).
- Permisos nuevos `driver_logs.{view,create,update,delete}` (`RolePermissionSeeder::PERMISSIONS`) —
  administrador y mantenimiento los tienen, chofer no (no está en `CHOFER_PERMISSIONS`).
  `DriverLogPolicy` auto-descubierta.

