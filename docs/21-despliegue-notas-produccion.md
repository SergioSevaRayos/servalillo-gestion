# Despliegue en producción — notas y gotchas

> Detalle movido desde CLAUDE.md (sin cambios de contenido). Los encabezados `###` originales se conservan.

## Despliegue (producción)
Runbook completo en **`docs/04-despliegue-vps.md`**. Resumen:
- **VPS bare-metal** (Hetzner CX23, Ubuntu 24.04): nginx + PHP 8.4-FPM (PPA `ondrej/php` — el lock
  exige >= 8.4.1) + PostgreSQL 16. **Sin Docker,
  sin Node, sin Reverb** (Echo no está cableado; `BROADCAST_CONNECTION=log`). Un `queue:work` bajo
  systemd + cron para `schedule:run`. Ficheros (PDF/firmas) en **Cloudflare R2** (disco `r2`).
- **Assets se compilan en el portátil y se suben** (el VPS no lleva Node). El deploy es
  **`server/deploy/deploy.sh`, que se ejecuta EN EL PORTÁTIL**: comprueba `main` limpio + push,
  corre los tests (`SKIP_TESTS=1` para saltarlos), `npm run build`, `git pull` en el VPS, `rsync` de
  `public/build/`, y por SSH `composer install --no-dev` + `migrate --force` + `optimize` +
  `queue:restart`. Config en `server/deploy/deploy.env` (gitignored; `VPS`/`APP_DIR`/`DOMAIN`).
- **Ficheros de infra** en `server/deploy/`: `nginx.conf`, `servalillo-worker.service`,
  `servalillo-backup.{sh,service,timer}` (pg_dump nocturno a R2), `crontab.txt`.
- **`.env` de producción**: plantilla en `server/.env.production.example` (`APP_ENV=production`,
  `APP_TIMEZONE=Europe/Madrid`, `SESSION_SECURE_COOKIE=true`, SMTP real, `R2_*`, `DEVICE_ENROLMENT_SECRET`…).
- **Seed de producción**: `php artisan db:seed --class=ProductionSeeder --force` (solo
  `RolePermissionSeeder` + `DeliveryTypeSeeder` — extraído de `DatabaseSeeder`, que NO se ejecuta en
  prod porque usa `fake()` y crea usuarios `.test`). El admin: `php artisan servalillo:crear-usuario`.
- **Código preparado para prod** (`AppServiceProvider`): `URL::forceScheme('https')` si
  `isProduction()`. `config/app.php` timezone pasa a `env('APP_TIMEZONE', 'UTC')`.
- **APK release = solo HTTPS**: `network_security_config.xml` deja el cleartext solo en
  `<debug-overrides>`. Pruebas LAN → `flutter build apk --debug`. Producción → `--release
  --dart-define-from-file=dart_define.production.json`.
- **⚠️ Cualquier cambio manual en el `.env` de producción (fuera de `deploy.sh`) exige recargar
  PHP-FPM, no solo `php artisan config:cache`.** Incidente real (2026-09-12, al activar
  `ATTENDANCE_ENABLED` para probar el Bloque 18 en caliente): edité el `.env` por SSH y corrí
  `config:cache`/`optimize` — comprobado por `tinker` (CLI), todo salía correcto
  (`config('servalillo.attendance.enabled')` ya daba `true`). Pero **`php` en modo CLI tiene
  OPcache desactivado por defecto** (`opcache.enable_cli=0`), así que esas comprobaciones no
  decían nada de lo que veían los procesos de **PHP-FPM** (los que de verdad atienden nginx), que
  con `opcache.validate_timestamps=0` (típico en producción, por rendimiento) siguen sirviendo el
  bytecode viejo de `bootstrap/cache/config.php` indefinidamente hasta que se reinician. Resultado:
  el usuario real, navegando por HTTPS, seguía sin ver "Fichar" en el nav ni el bloque de geovalla
  en `/usuarios`/`/chofers`, mientras todas mis comprobaciones por SSH decían que estaba todo bien
  — un falso positivo. `curl -I https://.../ruta-protegida` sin sesión **tampoco sirve** para
  detectar esto: una redirección 302 del middleware `auth` pasa igual sin llegar nunca al
  `config()` que falla, así que "la ruta responde" no prueba que el flag esté activo de verdad
  para los workers web. **Fix**: `sudo systemctl reload php8.4-fpm` después de cualquier
  `config:cache`/`optimize` manual — es justo lo que `deploy.sh` ya hace siempre tras un despliegue
  normal, por eso este problema nunca se había visto antes: solo aparece al tocar el `.env` a mano
  entre despliegues, no al desplegar con el script. **Regla a partir de ahora**: tras editar el
  `.env` de producción a mano, SIEMPRE `config:cache` (u `optimize`) **+ `systemctl reload
  php8.4-fpm`**, y verificar con una petición real autenticada (no con `curl` sin sesión ni con
  `tinker`), antes de dar el cambio por bueno.
