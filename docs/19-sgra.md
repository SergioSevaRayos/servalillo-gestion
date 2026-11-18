# Depósitos SGRA

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

### Depósitos SGRA (2026-09-11)

- **`/depositos`** (`App\Livewire\Sgra\Index`, permiso nuevo `sgra.view` — administrador +
  mantenimiento vía el criterio habitual, sin policy nueva porque no hay modelo Eloquent detrás)
  muestra el nivel de agua **en vivo** de los depósitos de un proyecto totalmente aparte del
  usuario, **SGRA** (Sistema de Gestión de Recursos del Aljibe: sensores ultrasónicos + LoRa/4G →
  MQTT → InfluxDB, dashboard Vue+FastAPI propio en su Raspberry Pi/NAS de casa) — pensado para no
  tener que salir de la app de reparto para saber cuánta agua queda antes de planificar rutas.
  **v1 deliberadamente simple**: solo % de llenado + estado por depósito, con un botón "Ver panel
  completo" que enlaza al dashboard SGRA de verdad para histórico/gráficas/alertas.
- **`App\Services\SgraClient`** es el único punto que habla con esa API externa — mismo espíritu
  que `RouteOptimizer`/`RouteGeometry` hablando con OSRM (nunca lanza al llamador; cualquier fallo
  de red/timeout/login/JSON inesperado se traga y devuelve `[]`, para que este panel se degrade con
  elegancia si el NAS está apagado o sin Tailscale, sin romper el resto de la app).
  - **La API de SGRA se autentica con sesión por cookie** (`POST /api/login`, sin token/API key) —
    y **el "depósito activo" es un concepto de sesión, no un parámetro** de las rutas de lectura
    (`GET /api/current` no acepta `tank_id`). Para leer TODOS los depósitos hace falta, con la
    MISMA cookie de sesión: `PUT /api/tanks/{id}/select` → `GET /api/current`, repetido por cada
    depósito (`SgraClient::resolveTankStatus()`). Cada llamada de `SgraClient` abre su propia
    sesión (`CookieJar` nuevo, `Http::withOptions(['cookies' => $jar])` reutilizado en las
    llamadas siguientes — el patrón estándar del cliente HTTP de Laravel/Guzzle para una API con
    sesión), así que no interfiere con la sesión real de nadie viendo el dashboard SGRA en su
    propio navegador.
  - **Cachea el resultado** (`Cache::remember('sgra:tanks', servalillo.sgra.cache_seconds, ...)`,
    30s por defecto) — sin esto, cada `wire:poll.60s` de cada pestaña de admin abierta dispararía
    un login + N pares de llamadas contra un Raspberry Pi doméstico.
  - Si falla `/api/current` de un depósito concreto (pero el login y el listado de depósitos sí
    funcionaron), esa tarjeta se marca "Sin datos" en vez de descartar el resto de depósitos.
- **Conectividad VPS↔NAS = Tailscale**, no la URL pública DuckDNS que ya usa el cliente en su
  navegador: `servalillo.sgra.base_url` apunta a la IP de Tailscale del NAS
  (`100.97.218.44:8090`), así que el VPS de producción tiene que estar unido al mismo tailnet
  (decisión explícita del usuario — más seguro que depender de HTTP sin cifrar por internet
  público). `servalillo.sgra.public_url` es un config **aparte**, solo para el botón "Ver panel
  completo" que abre el propio navegador del administrador (no lo usa el backend) — es
  `https://sgra.geosafety.es`, servido por este mismo VPS como proxy hacia el NAS (el router de
  casa ya no abre puertos, ver docs/04-despliegue-vps.md "Proxy SGRA"), porque el navegador del
  administrador no tiene por qué estar en el tailnet. **Requisito pendiente antes de activar en producción**: cambiar `DASH_PASSWORD` en el
  `.env` de SGRA (documentado ahí mismo como placeholder `admin` sin rotar) antes de meter esa
  contraseña real en el `.env` de Gestión Servalillo.
- `config('servalillo.sgra.enabled')` en `false` por defecto — local/tests nunca tocan red real
  (`phpunit.xml` fija `SGRA_ENABLED=false`); el portátil de desarrollo, al estar ya en el mismo
  tailnet que el NAS, puede activar `SGRA_ENABLED=true` en `server/.env` local para probar contra
  el SGRA real sin tocar producción.

