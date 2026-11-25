# Protección del terminal del chofer (2026-11-25)

Objetivo: que el chofer **no pueda hacer capturas de pantalla** de la web `/chofer/ruta`, con la
protección gestionada en remoto por administrador y mantenimiento.

## Por qué así (y no desde la web)
- Ninguna web puede bloquear las capturas: no hay HTML/CSS/JS para eso. El bloqueo de Chrome en
  incógnito lo hace el propio Chrome, poniendo `FLAG_SECURE` en su ventana.
- `FLAG_SECURE` solo protege las ventanas de la app que lo pone. Una app normal en 2º plano **no puede**
  bloquear las capturas de Chrome.
- Lo que sí puede es una app **Device Owner** (modo "móvil de empresa" de Android):
  `DevicePolicyManager.setScreenCaptureDisabled()` bloquea las capturas en **todo el móvil**, Chrome
  incluido, con el aviso del sistema "por la política de seguridad". La APK tracker (`mobile/`) es esa
  app. El chofer sigue usando la web en Chrome como antes.

## Qué hay
- **Restricciones** (columnas en `devices`): `block_screen_capture`, `block_uninstall`
  (`setUninstallBlocked` de la propia APK), `block_factory_reset` (`DISALLOW_FACTORY_RESET`) y
  `block_safe_boot` (`DISALLOW_SAFE_BOOT`). Además `release_requested` ("Liberar terminal": quita todo
  y llama a `clearDeviceOwnerApp`). Lo que reporta el móvil va en `reported_device_owner`,
  `reported_policies` (jsonb), `policies_reported_at` y `released_at`. Estado resumido para la UI:
  `Device::protectionState()`, que devuelve `none|pending|unmanaged|protected|released`.
- **Clave del terminal**: `unlock_pin_hash` + `unlock_pin_salt`, generados con
  `App\Services\DevicePinHasher` (PBKDF2-HMAC-SHA256, 100k iteraciones, sal hex de 16 bytes; la sal
  entra en el PBKDF2 **como cadena hex**, igual que `hash_pbkdf2`). Se usa PBKDF2 y no bcrypt porque
  la APK verifica **sin conexión** con el hash, la sal y las iteraciones, y PBKDF2 existe igual en PHP
  y en Dart (`mobile/lib/services/pin_verifier.dart`). Los tests `tests/Unit/DevicePinHasherTest.php` y
  `mobile/test/pin_verifier_test.dart` comparten un **vector fijo**: si cambia el algoritmo en un
  lado, fallan los dos. La clave está en `$hidden` y en `$auditExclude`, y nunca viaja en claro.
- **API** (mismo `auth:sanctum` + `abilities:gps:ingest`):
  - `GET /api/device` añade `policies {block_*, release}` y `unlock {hash, salt, iterations}|null`.
  - `POST /api/device/state` (`ReportDeviceStateRequest`): `{device_owner, applied{block_*}, app_version?}`.
    Si había `release_requested` y llega `device_owner=false`, lo cierra (`released_at`). No comprueba
    `is_active` a propósito, para que un dispositivo desactivado también pueda liberarse.
- **Panel**: la pantalla Dispositivos **se movió de `/mantenimiento/dispositivos` a `/dispositivos`**
  (ruta `devices.index`, grupo "Datos" del menú, permiso `devices.manage`, que tienen administrador y
  mantenimiento). Componente `App\Livewire\Devices\Index` (antes `Maintenance\Devices`). Columna
  "Protección" y modal **Seguridad** (`DeviceSecurityForm`):
  - las 4 restricciones, con lo aplicado en el móvil debajo de cada una;
  - la clave (obligatoria la primera vez; en blanco = se mantiene);
  - **Liberar terminal**;
  - **Aplicar a todos**: copia las restricciones a todos los activos, uno a uno para que quede en la
    auditoría, y nunca copia la clave.
- **APK**:
  - Plugin local `mobile/packages/device_policy/` (Kotlin, `AdminReceiver` + `DevicePolicyPlugin`).
    Tiene que ser un plugin y no un `MethodChannel` en `MainActivity`, para que se registre también en
    el engine del servicio en 1er plano.
  - `PolicySyncService`: `GET /api/device` → aplicar o liberar → `POST /api/device/state`. Sin red,
    reaplica la última política guardada en `SecureStore`. Lo llaman `TrackerTaskHandler` (al arrancar
    y cada 5 min, **antes** de la pausa nocturna y aunque haya ticks saltados por `Retry-After`) y la
    pantalla de la APK (cada 60 s).
  - `PinGate` envuelve la pantalla: si hay clave, la pide. Tras 5 fallos bloquea 5 min (guardado en
    `SecureStore`), y la vuelve a pedir al salir de la app.
  - La tarjeta "Protección del terminal" de la pantalla muestra lo aplicado. **Solo con clave
    configurada** ofrece "Quitar restricciones 30 min" (`suspend`, sin red) y "Liberar terminal"
    (`releaseLocally`).
  - Si la APK no es Device Owner, todo es *no-op* y reporta `device_owner=false`; en el panel sale
    "Sin administración".

## Descarga de la APK desde la web
- Botón **"Descargar APK del tracker"** en `/dispositivos` → `GET /dispositivos/apk` (`devices.apk`,
  `TrackerApkController`, permiso `devices.manage` + `DevicePolicy@viewAny`). Si no hay APK publicada
  devuelve 404, y la pantalla lo indica en lugar del botón.
- La APK **no va en git** (~60 MB). Está en `storage/app/private/apk/servalillo-tracker.apk`
  (disco `local`) junto a `tracker.json` (`version`, `built_at`, `sha256`), y la lee
  `App\Services\TrackerApk`.
- La publica **`bash server/deploy/publish-apk.sh`** (en el portátil, independiente de `deploy.sh`):
  compila con `mobile/dart_define.production.json` (versión = su `APP_VERSION`, **súbela** antes de
  publicar) y la sube por rsync al VPS.
  - `LOCAL=1`: compila con `dart_define.json` y la deja en el `storage` local.
  - `NO_BUILD=1`: sube la última APK ya compilada.
- **Firma**: la release se firma con la clave *debug* de este portátil. Para que un móvil se actualice
  encima (sin desinstalar, cosa que además impide `block_uninstall`), hay que compilar siempre desde
  aquí. Si algún día se cambia a un keystore propio, los móviles con la APK antigua tendrán que
  liberarse y reinstalarse.
- La APK lleva dentro `ENROLMENT_SECRET`: por eso la descarga solo está disponible para quien
  gestiona dispositivos.

## Alta de cada terminal (una vez, servicio técnico)
Android solo permite el alta como Device Owner si el móvil **no tiene ninguna cuenta** (ni la de
Google). Un móvil recién restablecido con solo el tracker instalado vale tal cual.
1. Ajustes → Cuentas: quitar todas las cuentas, si hay alguna.
2. Activar Opciones de desarrollador → Depuración USB y conectar al portátil.
3. Instalar o actualizar la APK (descargada desde `/dispositivos` o compilada):
   `adb install -r servalillo-tracker.apk`. Para actualizar sin perder el
   enrolamiento, tiene que estar **firmada con la misma clave** que la instalada (hoy, la debug de este
   portátil).
4. `adb shell dpm set-device-owner es.servalillo.tracker/es.servalillo.device_policy.AdminReceiver`
   → debe responder `Success: Device owner set to package ...`. Si dice que ya hay cuentas o usuarios,
   repetir el paso 1.
5. Abrir la APK y conceder los permisos, como siempre (`mobile/README.md`).
6. Volver a añadir la cuenta de Google, si hace falta.
7. Web → Dispositivos: asignar el chofer, pulsar **Seguridad**, poner la clave y marcar las
   restricciones. Antes de 5 minutos el móvil sale como "Protegido".

## Límites y gotchas
- El restablecimiento desde **recovery** (botones físicos) no se puede bloquear del todo. Si pasa,
  el móvil pierde el tracker y lo detectan los avisos por falta de señal GPS.
- **Antes de retirar o reutilizar un móvil hay que "Liberar terminal"**: con `block_factory_reset`
  no se puede restablecer desde Ajustes, y con `block_uninstall` no se desinstala la APK.
- Si el dispositivo se **desactiva o revoca** en el panel, el servicio de la APK se para y deja de
  sincronizar la protección, aunque las restricciones ya aplicadas siguen en el móvil (persisten tras
  reiniciar). Para liberarlo en ese caso: reactivarlo, o liberarlo desde la APK con la clave.
- Liberar desde el propio móvil (sin red) deja la web pidiendo restricciones a un móvil que ya no es
  administrado: saldrá "Sin administración". Volver a protegerlo exige repetir el `adb`.
- Solo Android. En iPhone no existe un equivalente.
- La suspensión de 30 min se reporta tal cual, así que el panel muestra "Pendiente" mientras dura.

## Tests
- Servidor: `tests/Feature/DevicesTest.php` (antes `MaintenanceDevicesTest`),
  `tests/Feature/Api/DeviceStateTest.php` y `tests/Unit/DevicePinHasherTest.php`.
- APK: `mobile/test/policy_sync_service_test.dart` (con un plugin falso) y
  `mobile/test/pin_verifier_test.dart`.
