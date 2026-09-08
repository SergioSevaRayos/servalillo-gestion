# Servalillo Tracker (APK)

APK Android **sin interfaz de uso** que comparte la ubicación del móvil de empresa de cada camión con
Gestión Servalillo. La instala el **servicio técnico**: se abre una vez para conceder permisos y a
partir de ahí comparte la ubicación en segundo plano de forma permanente. **No hay login ni pantallas
para el chofer.**

Consume la API del Bloque 10/11 del servidor:

- `POST /api/device/register` — enrolamiento con secreto compartido → token Sanctum (`gps:ingest`).
- `GET /api/device` — estado (chofer asignado, activo, config de tracking).
- `POST /api/gps/batch` — lotes de ≤500 posiciones (offline-friendly).

## Cómo funciona

- **Servicio en primer plano** (`flutter_foreground_task`): cada `ping_interval_seconds` (lo manda el
  servidor) toma una posición, la guarda en SQLite y vacía la cola contra `/api/gps/batch`.
- **Cola offline** (`sqflite`): sin red, la cola crece (tope ~50 000 filas ≈ 26 días) y se vacía al
  volver. Se borra el lote entero ante cualquier 2xx.
- **Ventana de pausa**: entre `pause_start` y `pause_end` (por defecto 22:00–05:00) el servicio sigue
  vivo pero no muestrea ni envía.
- **Arranque en boot** (`RECEIVE_BOOT_COMPLETED`): revive tras reiniciar el móvil, **solo** si está
  concedido el permiso de ubicación "todo el tiempo" (si no, Android 14/15 mata el servicio).
- **Pantalla de estado** (única pantalla): botón de permisos + servicio, enrolado, chofer asignado,
  última señal, última posición enviada y pendientes en cola.

## Requisitos de compilación

No hace falta Android Studio. Sí:

- **Flutter** 3.41+ (Dart 3.11+).
- **JDK 17** (AGP no funciona con Java 21/25). Portable vale:
  `flutter config --jdk-dir <ruta-jdk-17>`.
- **Android SDK** con `platform-tools`, `platforms;android-36`, `build-tools;36.0.0` y licencias
  aceptadas (`yes | sdkmanager --licenses`). `flutter config --android-sdk <ruta-sdk>`.
- `flutter doctor -v` debe estar verde para Android.

## Configuración (obligatoria antes de compilar)

La config se hornea en el APK en tiempo de compilación con `--dart-define`. No se puede cambiar sin
recompilar.

```bash
cp dart_define.example.json dart_define.json
```

Edita `dart_define.json` (gitignored):

| Clave | Qué es |
|---|---|
| `SERVER_URL` | URL base del servidor accesible desde el móvil (dev tunnel de VSC, o `http://IP-LAN:8000`). Sin `/` final. |
| `ENROLMENT_SECRET` | El `DEVICE_ENROLMENT_SECRET` del `.env` del servidor. |
| `APP_VERSION` | Texto libre, se guarda en el panel de dispositivos. |

En el servidor: `DEVICE_ENROLMENT_SECRET` en `.env`, `php artisan config:clear`. Si el servidor va
tras un túnel/proxy, `APP_URL` correcto y `trustProxies` activo (ya lo está).

## Compilar el APK

```bash
flutter pub get
flutter build apk --release --dart-define-from-file=dart_define.json
```

Sale en `build/app/outputs/flutter-apk/app-release.apk`. Está firmado con la clave **debug** (vale
para sideload; para distribución real, keystore propio + `android/key.properties`).

## Instalar

- Con cable: `adb install -r build/app/outputs/flutter-apk/app-release.apk`.
- Sin cable: pasar el `.apk` al móvil (USB, Drive, etc.) y abrirlo; permitir "instalar apps de esta
  fuente".

Al abrir la app por primera vez, pulsar el botón de permisos y conceder, **en este orden**:

1. **Ubicación** → "Permitir mientras se usa la app".
2. **Ubicación "todo el tiempo"** → Android abre Ajustes: elegir "Permitir todo el tiempo" (Android
   11+ no muestra diálogo, hay que ir a Ajustes de la app → Permisos → Ubicación).
3. **Notificaciones** → permitir (es la notificación fija del servicio).
4. **Batería sin restricción** → permitir (para que el sistema no lo mate).

Cuando las 4 estén concedidas y el dispositivo esté enrolado, el servicio arranca solo.

## Asignar el dispositivo a un chofer

`soporte@servalillo.test` → **Mantenimiento → Dispositivos**. El dispositivo aparece enrolado "en
blanco". Asignarle un chofer; a partir de ahí las posiciones traen `driver_id` y, si ese chofer tiene
ruta ese día, `truck_id` / `route_id`.

## Ajustes por fabricante (mataprocesos OEM)

Muchos Android "optimizan" agresivamente y matan servicios en segundo plano. Además de la exención de
batería, en el móvil de cada camión conviene:

- **Xiaomi / Redmi / POCO (MIUI)**: Ajustes → Apps → Servalillo Tracker → **Inicio automático: ON**;
  "Ahorro de batería" → **Sin restricciones**; en Recientes, fijar la app con el candado.
- **Samsung (One UI)**: Ajustes → Batería → Límites de uso en segundo plano → quitar la app de "en
  reposo" y "en reposo profundo"; Apps → Servalillo Tracker → Batería → **Sin restricciones**.
- **Huawei / Honor (EMUI)**: Ajustes → Batería → Inicio de app → Servalillo Tracker → **Gestión
  manual** con las 3 opciones activadas (inicio automático, inicio secundario, ejecución en segundo
  plano).
- **OPPO / realme / vivo (ColorOS / FuntouchOS)**: permitir inicio automático y ejecución en segundo
  plano; desactivar la optimización de batería para la app.
- **Android "puro" (Pixel, Motorola, Nokia)**: solo la exención de batería suele bastar.

Referencia general: <https://dontkillmyapp.com>.

## Solución de problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| Pantalla en rojo "Falta configurar…" | Se compiló sin `ENROLMENT_SECRET` | Rellenar `dart_define.json` y recompilar. |
| "No se pudo contactar con el servidor" | `SERVER_URL` no accesible desde el móvil, o túnel caído | Abrir `SERVER_URL` en el navegador del móvil; revisar el dev tunnel. |
| Enrolado pero "Chofer: sin asignar" | Falta asignarlo en el panel | Mantenimiento → Dispositivos → asignar. |
| "El acceso fue revocado" | El técnico revocó el token | Pulsar "Re-enrolar". |
| "Dispositivo desactivado" | Desactivado en el panel | Reactivarlo en Mantenimiento → Dispositivos. |
| Deja de enviar tras un rato con la pantalla apagada | Mataprocesos OEM | Ver "Ajustes por fabricante". |
| No revive tras reiniciar el móvil | Falta "ubicación todo el tiempo" | Concederla en Ajustes de la app. |
| No envía entre las 22:00 y las 05:00 | Es la ventana de pausa (correcto) | — |

## Tests

```bash
flutter test          # lógica Dart (53 tests)
flutter analyze
dart format --set-exit-if-changed .
```

## Estructura

```
lib/
  config/app_config.dart          # --dart-define
  core/{clock,backoff}.dart
  models/                          # tracked_position, tracking_config, device_status, ...
  data/                            # app_database, position_queue, secure_store
  services/                       # tracker_api, enrolment_service, sync_service,
                                  # pause_window, location_sampler, tracker_controller
  foreground/                     # foreground_service, tracker_task_handler
  ui/                             # status_screen, permission_flow
  main.dart  app.dart
```
