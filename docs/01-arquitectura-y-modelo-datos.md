# Bloque 0 — Propuesta de arquitectura y modelo de datos

> Estado: **pendiente de validación**. No se ha escrito código de aplicación todavía.
> Fecha: 2026-09-04

---

## 1. Estructura del proyecto

### Decisión: **monorepo** (un solo repositorio Git)

```
gestion-servalillo/
├── server/             # Laravel 13 (API + web admin + web chofer). Stack local en server/compose.yaml
├── mobile/             # Flutter (app de tracking GPS)
├── docs/               # este documento, ADRs, diagramas
└── README.md
```

En la VPS el document root de nginx apunta a `server/public`; el resto del monorepo
(`mobile/`, `docs/`) queda fuera del path público.

**Por qué monorepo y no repos separados:**
- Equipo pequeño (1-2 personas). Un cambio de contrato de API toca servidor y Flutter a la vez → un solo PR, un solo historial.
- No hay pipeline de release independiente por ahora (todo local).
- `docs/` compartido y versionado junto al código.
- Si en el futuro se separa, `git subtree split` lo permite sin perder historia.

### Entorno local: **Laravel Sail** (Docker)

**Por qué Sail y no Herd/Valet:**
- Tu host tiene **PHP 8.5.4**; Laravel 12 está certificado para 8.2–8.4. Sail fija la versión de PHP dentro del contenedor (imagen 8.4) y evita sorpresas.
- PostgreSQL, Reverb y el worker de colas se levantan con `sail up` sin instalar nada a mano.
- Reproducible: cuando toque VPS, el `Dockerfile` de producción parte de la misma base.

Contenedores: `laravel.test` (app), `pgsql`, `reverb` (websockets), `queue` (worker `database`), y `mailpit` (captura de emails de albaranes en local). Redis **no** se usa por ahora (colas y cache en `database`/`file`, como pediste).

---

## 2. Sistema de roles y permisos (Spatie Laravel Permission)

### 3 roles
| Rol | Descripción |
|---|---|
| `administrador` | Gestión completa del negocio + panel estadístico |
| `chofer` | Solo su vista operativa (su ruta del día) |
| `mantenimiento` | Todo lo de administrador + panel de logs/auditoría (rol de soporte técnico, el nuestro) |

### Permisos granulares (no se asigna por rol "a pelo", se asigna por permiso)

```
drivers.view      drivers.create      drivers.update      drivers.delete
trucks.view       trucks.create       trucks.update       trucks.delete
routes.view       routes.create       routes.update       routes.delete
routes.view.own                                            routes.reorder_stops
deliveries.complete        deliveries.record_signature
odometer.record
delivery_notes.view        delivery_notes.regenerate       delivery_notes.mark_delivered
stats.view
users.manage      roles.manage
devices.manage
audits.view       system_logs.view
gps.ingest        (permiso técnico para el token del dispositivo Flutter)
```

Asignación:
- `administrador` → todos menos `audits.view`, `system_logs.view`, `gps.ingest`.
- `mantenimiento` → **todos**.
- `chofer` → `routes.view.own`, `deliveries.complete`, `deliveries.record_signature`, `odometer.record`, `delivery_notes.view` (solo los suyos vía Policy).

### Estrategia de autorización
- **Una Policy por modelo** (`DriverPolicy`, `TruckPolicy`, `RoutePolicy`, `RouteStopPolicy`, `DeliveryNotePolicy`, `UserPolicy`, `AuditPolicy`).
- Cada método de Policy comprueba **permiso + propiedad** (ej. `RoutePolicy::view` deja pasar si tiene `routes.view` **o** (`routes.view.own` y la ruta es de su chofer)).
- `Gate::before` para que `mantenimiento` no tenga que enumerar permisos (equivale a super-admin), pero **manteniendo** el registro de auditoría.
- Los componentes Livewire llaman `$this->authorize(...)` en cada acción; la API usa `authorizeResource` / middleware `can:`.

---

## 3. Modelo de datos

### 3.1 Diagrama de entidades (resumen)

```
users ──1:1── drivers
users ──1:1── (perfil, sin tabla extra para admin/mantenimiento)

trucks ──1:N── truck_assignments ──N:1── drivers      (historial de asignación camión↔chofer)
trucks ──1:1── devices                                (móvil de empresa dedicado)

routes ──N:1── trucks
routes ──N:1── drivers
routes ──1:N── route_stops                            (paradas, ordenadas por `position`)
routes ──1:N── odometer_readings                      (inicio / fin de jornada)

route_stops ──N:1── delivery_types                    (tipo de reparto → esquema de campos)
route_stops ──1:1── delivery_notes                    (albarán, cuando se completa)

trucks ──1:N── gps_positions
devices ──1:N── gps_positions

audits            (owen-it/laravel-auditing, polimórfica)
error_logs        (excepciones capturadas para el panel de mantenimiento)
jobs / failed_jobs (colas driver database)
```

### 3.2 Tablas

**users**
`id, name, email (unique), password, phone, is_active (bool), theme_preference (enum: system|light|dark, default system), last_login_at, timestamps, soft_deletes`

**drivers** (datos propios del chofer; 1:1 con `users`)
`id, user_id (unique FK), employee_code, license_number, license_expiry (date), phone, notes, is_active, timestamps, soft_deletes`

**trucks**
`id, plate (unique), code (unique, ej. "C-03"), description, capacity_liters (int), compartments (smallint), model, year, odometer (int, última lectura conocida), is_active, notes, timestamps, soft_deletes`

**truck_assignments** (quién conduce qué, con historia)
`id, truck_id FK, driver_id FK, valid_from (date), valid_until (date, null = vigente), timestamps`

**devices** (móvil de empresa que hace el tracking)
`id, truck_id (FK, unique), label, platform (android), install_identifier (string, unique), app_version, last_seen_at, is_active, timestamps`
- Autenticación del dispositivo: **token Sanctum** propio (no el del chofer), con habilidad `gps:ingest`. Se emite una vez en el login inicial de la app.

**routes**
`id, code (generado, ej. R-20260904-03), route_date (date), truck_id FK, driver_id FK, status (enum: draft|published|in_progress|completed|cancelled), name, notes, started_at, completed_at, created_by (user_id), timestamps, soft_deletes`
- Índice único `(truck_id, route_date)` opcional (un camión = una ruta por día). A validar contigo.

**delivery_types** (el corazón del "modelo flexible de viajes/repartos")
`id, name, slug (unique), description, is_active, field_schema (JSONB), timestamps`

`field_schema` = array de definiciones de campo, ej.:
```json
[
  { "key": "producto",      "label": "Producto",           "type": "select", "required": true,
    "options": ["Gasóleo A", "Gasóleo B", "Gasóleo C"] },
  { "key": "litros_pedido",  "label": "Litros pedidos",     "type": "number", "required": true, "unit": "L", "min": 0 },
  { "key": "precio_litro",   "label": "Precio / litro",     "type": "number", "required": false, "unit": "€" },
  { "key": "requiere_bomba", "label": "Requiere bomba propia","type": "boolean","required": false }
]
```

**route_stops** (parada / tarea de una ruta)
`id, route_id FK, position (int, orden para drag&drop), customer_name, customer_tax_id, address, latitude, longitude, contact_name, contact_phone, delivery_type_id FK, status (enum: pending|in_progress|completed|skipped|failed), scheduled_window_start, scheduled_window_end, planned_quantity (numeric, null), delivered_quantity (numeric, null), completed_at, failure_reason, data (JSONB — valores de los campos definidos en delivery_types.field_schema), timestamps, soft_deletes`

- El `data` JSONB se **valida en un Service** (`DeliveryTypeSchemaValidator`) contra `field_schema` antes de cada guardado. Nunca se confía en el cliente.
- Reordenar = actualizar `position` en lote (endpoint / acción Livewire dedicada, permiso `routes.reorder_stops`).

**delivery_notes** (albarán)
`id, route_stop_id (FK, unique), number (unique, generado — ej. ALB-2026-000123), issued_at, customer_snapshot (JSONB — nombre/CIF/dirección congelados), delivered_quantity, odometer_reading (int, null), signature_path (string, null), signer_name, delivery_channel (string — "email" | "physical" | futuro "whatsapp"), recipient_email (null), pdf_path (null), status (enum: pending|queued|generating|generated|sent|delivered_physically|failed), failure_reason, sent_at, delivered_at, delivered_by (user_id, null — quién marcó la entrega física), created_by (user_id), timestamps`

- **Canales extensibles sin migración:** `delivery_channel` es un `string`, no un enum de BD. Cada canal es una clase que implementa `DeliveryChannel` (`EmailChannel`, `PhysicalChannel`), registrada en `config/delivery.php`. Añadir WhatsApp = nueva clase + entrada en config + (si necesita) columnas nullables. La lógica de "completar entrega" no cambia.
- Firma: fichero en disco `r2` (local: `local`), **nombre generado por el sistema** (`ulid().png`), validación de MIME real (`image/png`).

**odometer_readings** (lectura manual del contador)
`id, route_id FK, truck_id FK, driver_id FK, kind (enum: start|end), value (int), recorded_at, notes, timestamps`
- Único `(route_id, kind)`. Al guardar `end`, se actualiza `trucks.odometer` vía Service.

**gps_positions** (alto volumen)
`id (bigint), truck_id FK, device_id FK, driver_id (null), route_id (null), latitude, longitude, accuracy_m, speed_mps, heading_deg, battery_level (smallint, null), recorded_at (timestamp del dispositivo), created_at`
- Índice `(truck_id, recorded_at desc)`. Sin `updated_at` (append-only).
- Se emite por **Reverb** solo la última posición de cada camión (canal privado `map`), no todo el histórico.
- Retención: job de limpieza (> 90 días) — configurable, a validar.

**error_logs** (para el panel de mantenimiento)
`id, level, message, exception_class, file, line, context (JSONB), user_id (null), url, method, occurred_at, created_at`
- Alimentada desde el `report()` del Handler de excepciones + un canal de log `database`.
- Los logs de fichero (`storage/logs/laravel.log`) se consultan además con un visor (paquete `opcodesio/log-viewer`).

**audits** — tabla estándar de `owen-it/laravel-auditing`. Modelos auditados desde el primer día: `User, Driver, Truck, Route, RouteStop, DeliveryNote, DeliveryType, OdometerReading, Device`.

### 3.3 Enums como clases PHP (`app/Enums`)
`RouteStatus, RouteStopStatus, DeliveryNoteStatus, OdometerKind, ThemePreference`. Backed enums, casteados en los modelos.

---

## 4. Endpoints API (Sanctum) — contrato inicial

| Método | Ruta | Auth | Uso |
|---|---|---|---|
| POST | `/api/auth/login` | — (rate-limited 5/min) | Chofer y dispositivo. Devuelve token |
| POST | `/api/auth/logout` | token | Revoca el token actual |
| GET | `/api/me` | token | Perfil + rol |
| GET | `/api/routes/today` | chofer | Su ruta del día + paradas ordenadas |
| POST | `/api/stops/{stop}/start` | chofer | Marca parada en curso |
| POST | `/api/stops/{stop}/complete` | chofer | qty entregada + data + canal albarán |
| POST | `/api/stops/{stop}/signature` | chofer | Sube PNG de firma (MIME validado) |
| POST | `/api/stops/{stop}/fail` | chofer | Marca parada fallida + motivo |
| POST | `/api/routes/{route}/odometer` | chofer | Lectura inicio/fin |
| POST | `/api/gps/batch` | dispositivo (`gps:ingest`) | Lote de posiciones (offline-friendly) |

Todo con **Form Requests** + **API Resources**. La web del chofer usa Livewire (sesión web), no esta API — la API es sobre todo para Flutter, pero el contrato queda disponible por si la web del chofer migra a SPA.

---

## 5. Orden de construcción (recordatorio del plan)

1. **Backend base** — Sail, Laravel 12, Sanctum, Spatie Permission, laravel-auditing, migraciones, modelos, Policies, seeders (usuarios de cada rol, 4 camiones, 4 chofers, 2 delivery_types, 3 rutas de ejemplo).
2. **Sistema de diseño Tailwind** — `tailwind.config.js` (paleta 5 tonos, tipografía, radios, sombras), componentes Blade (`x-button`, `x-input`, `x-table`, `x-card`, `x-badge`, `x-modal`), modo claro/oscuro con persistencia, botón gota animado.
3. **Panel Administrador** — CRUDs Livewire (chofer, camión, ruta, usuario) con búsqueda/filtro/orden/paginación + tabla→tarjetas en móvil.
4. **Drag & drop de paradas** (SortableJS + Livewire).
5. **Panel estadístico** (KPIs).
6. **Panel de Mantenimiento** (auditoría + error_logs + log-viewer).
7. **Web del chofer** (alto contraste, sin glassmorphism).
8. **Albaranes** — Spatie PDF, canales email/físico, colas.
9. **API Flutter** (Sanctum).
10. **App Flutter** de tracking.

---

## 6. Puntos concretos que necesito que valides

1. **Monorepo + Sail** — ¿de acuerdo?
2. **Modelo flexible = `delivery_types.field_schema` (JSONB) + `route_stops.data` (JSONB) validado en Service.** Alternativa era EAV (tablas `custom_fields`/`custom_field_values`): más consultable con SQL puro pero mucho más pesado de mantener y más lento. Recomiendo JSONB porque PostgreSQL lo indexa (GIN) y el volumen es bajo. ¿Lo apruebas?
3. **`route_stop` = parada planificada + ejecución en la misma tabla** (no una tabla `deliveries` separada). El albarán sí es tabla aparte. ¿OK o prefieres separar planificación de ejecución?
4. **Un camión = una ruta por día** (índice único `truck_id + route_date`). ¿Es una regla real del negocio o puede haber 2 rutas/día para el mismo camión?
5. **`drivers` como tabla 1:1 con `users`** (vs. meter los campos del chofer en `users`). Recomiendo tabla aparte por limpieza. ¿OK?
6. **Retención de GPS a 90 días.** ¿Sirve? ¿Necesitáis más por temas legales/laborales?
7. **Canal de albarán como `string` + clases de estrategia** (no enum de BD). ¿OK?
