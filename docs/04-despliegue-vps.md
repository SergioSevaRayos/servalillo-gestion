# Despliegue en producción (VPS)

Guía para poner Gestión Servalillo en un VPS único. Desarrollo sigue en Docker local; producción
es bare-metal (nginx + PHP-FPM + PostgreSQL), sin Docker y **sin Node** (los assets se compilan en
el portátil y se suben).

- **VPS**: Hetzner CX23 (2 vCPU / 4 GB / 40 GB) o CAX11 (ARM), **Ubuntu 24.04 LTS**.
- **PHP 8.4** vía PPA `ondrej/php` (el `composer.lock` trae Symfony 8 por Laravel 13, exige ≥ 8.4.1).
- **Ficheros** (PDF de albarán + firmas): Cloudflare R2 (bucket privado).
- **Despliegue**: `server/deploy/deploy.sh`, se ejecuta **desde el portátil**.
- **Coste**: ~€6-9/mes (VPS + dominio; R2 gratis a esta escala).

---

## Estado del despliegue (2026-09-10)

`servalillo-prod` (Hetzner CX23, **IPv4 `2.28.64.188`**, Nuremberg) **en producción con HTTPS**:

- Sistema, usuario `deploy` (SSH solo por clave `~/.ssh/servalillo`; root deshabilitado; contraseña
  hex de `deploy` como break-glass de consola), UFW + fail2ban + unattended-upgrades.
- Stack: nginx + PHP 8.4-FPM + PostgreSQL 16. BD `servalillo` + rol `servalillo`.
- Repo en `/var/www/servalillo`, `.env` con `APP_KEY`/`DB_PASSWORD`/`DEVICE_ENROLMENT_SECRET`,
  30 migraciones + `ProductionSeeder`. Worker systemd (`servalillo-worker`) + cron `schedule:run`.
- **Dominio `geosafety.es`** (registrado en Hostinger, DNS ahí: `A @` → `2.28.64.188`,
  `CNAME www` → `geosafety.es`). Cert Let's Encrypt vía `certbot --nginx` (sin email, renovación
  automática por timer); http → https 301. `APP_URL=https://geosafety.es`, `SESSION_SECURE_COOKIE=true`.
- **La app responde en `https://geosafety.es`**. Primer usuario creado (rol `mantenimiento`).
- `server/deploy/deploy.env` (portátil): `VPS=deploy@2.28.64.188`, `APP_DIR=/var/www/servalillo`,
  `DOMAIN=geosafety.es`.

- **SMTP**: buzón `soporte@geosafety.es` (Hostinger/Titan). **Hetzner bloquea saliente los puertos
  25 y 465 por defecto** (anti-spam en VPS nuevos) → usar **587 con STARTTLS**
  (`MAIL_PORT=587`, `MAIL_SCHEME=smtp`, no `smtps`). Envío de prueba OK.
- **APK release**: compilada apuntando a `https://geosafety.es` (`mobile/dart_define.production.json`,
  gitignored). Lista para instalar en los móviles de los camiones.

**Falta** (necesita datos externos): Cloudflare R2 — **decisión pendiente**: los PDFs/firmas ya
funcionan en local (`storage/app/private/r2`, fallback automático sin `R2_ACCESS_KEY_ID`) y un
albarán no tiene los plazos de conservación fiscal de una factura, así que puede que no haga falta.
Si se quiere backup de esos ficheros sin montar R2 completo, alternativa: `rclone` a algún destino
gratuito (Google Drive, etc.) solo para la copia, sin cambiar el disco de servicio.

---

## 0. Antes de nada: subir el trabajo a `main`

El VPS despliega desde la rama `main`. Ahora mismo hay commits en `develop` sin subir.

```bash
git checkout develop && git push origin develop
git checkout test  && git merge develop --ff-only && git push origin test
git checkout main  && git merge test    --ff-only && git push origin main
git checkout develop
```

---

## 1. Crear el VPS (Hetzner Console)

Ver también la guía rápida que seguimos por chat. Resumen:

1. `console.hetzner.cloud` → proyecto **Servalillo** → **Security → SSH Keys** → añade tu clave
   pública (`~/.ssh/servalillo.pub`).
2. **Servers → Add Server**: Location `Nuremberg`, Image **Ubuntu 24.04**, Type **CX23** (Shared
   vCPU, línea Cost-Optimized x86), Networking IPv4 + IPv6, marca tu SSH key, Name `servalillo-prod`.
   **Create & Buy now**.
3. **Firewalls → Create Firewall** `servalillo-fw`: Inbound TCP 22, 80, 443 (desde cualquier IP).
   Aplícalo al servidor.
4. Copia la **IPv4** del servidor.

DNS del dominio (en tu registrador o en Hetzner DNS):

| Tipo | Nombre | Valor |
|---|---|---|
| A | `@` (y `www` si quieres) | IPv4 del VPS |
| AAAA | `@` | IPv6 del VPS |

---

## 2. Preparar el sistema (una vez, por SSH como `root`)

```bash
ssh -i ~/.ssh/servalillo root@LA_IP

# Usuario de despliegue con sudo
adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
# php-fpm reload sin contraseña (lo usa deploy.sh)
echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm, /usr/bin/systemctl restart php8.4-fpm, /usr/bin/systemctl reload nginx' > /etc/sudoers.d/deploy-fpm
chmod 440 /etc/sudoers.d/deploy-fpm
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/ && chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh

# Endurecer SSH: sin root, sin contraseña
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/; s/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

# Firewall del SO (además del de Hetzner), fail2ban, updates automáticos
apt update && apt -y upgrade
apt -y install ufw fail2ban unattended-upgrades
ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw --force enable
systemctl enable --now fail2ban
dpkg-reconfigure -f noninteractive unattended-upgrades

# PHP 8.4 desde ondrej/php (Ubuntu 24.04 solo trae 8.3; el composer.lock —Symfony 8 vía
# Laravel 13— exige PHP >= 8.4.1, que es además la versión del contenedor de desarrollo).
add-apt-repository -y ppa:ondrej/php
apt update

# Stack (PostgreSQL 16 sí es nativo de Ubuntu 24.04)
apt -y install nginx postgresql \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-zip php8.4-intl php8.4-bcmath \
  php8.4-gd php8.4-mbstring php8.4-xml php8.4-curl php8.4-opcache \
  composer git rsync certbot python3-certbot-nginx rclone unzip

# opcache recomendado para prod
cat > /etc/php/8.4/fpm/conf.d/99-servalillo.ini <<'EOF'
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=128
opcache.max_accelerated_files=20000
expose_php=Off
EOF
systemctl restart php8.4-fpm
```

### Base de datos

```bash
sudo -u postgres psql <<'EOF'
CREATE USER servalillo WITH PASSWORD 'PON_UNA_CONTRASEÑA_FUERTE';
CREATE DATABASE servalillo OWNER servalillo;
EOF
```

### Código

```bash
# como deploy a partir de aquí
su - deploy
sudo mkdir -p /var/www/servalillo && sudo chown deploy:deploy /var/www/servalillo
git clone https://github.com/SergioSevaRayos/servalillo-gestion.git /var/www/servalillo
cd /var/www/servalillo/server
cp .env.production.example .env
nano .env      # APP_URL, APP_TIMEZONE, DB_PASSWORD, MAIL_*, R2_*, DEVICE_ENROLMENT_SECRET, BASE_*
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate
```

Permisos de escritura para php-fpm (usuario `www-data`). Con setgid + ACL por defecto, los ficheros
que cree luego `deploy` (`optimize`, caché de vistas) ya nacen escribibles por `www-data`:

```bash
sudo apt -y install acl
cd /var/www/servalillo/server
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
sudo setfacl -R -m g:www-data:rwX -m d:g:www-data:rwX storage bootstrap/cache
```

---

## 3. Primer deploy de assets (desde el portátil)

```bash
cd ~/VSC/gestion-servalillo
cp server/deploy/deploy.env.example server/deploy/deploy.env
nano server/deploy/deploy.env      # VPS=deploy@LA_IP · APP_DIR=/var/www/servalillo · DOMAIN=tudominio.com

git checkout main
bash server/deploy/deploy.sh
```

`deploy.sh` compila los assets, sube `public/build` por rsync, corre `composer install`,
`migrate --force`, `optimize`, reinicia el worker y hace el smoke check.

---

## 4. Semilla y primer usuario (en el VPS)

```bash
cd /var/www/servalillo/server
php artisan db:seed --class=ProductionSeeder --force     # roles + permisos + tipo de reparto "agua"
php artisan servalillo:crear-usuario                     # el PRIMER usuario (arranque)
```

`servalillo:crear-usuario` pregunta nombre, email, contraseña (mín. 10) y rol
(`administrador` | `mantenimiento`). Necesita una TTY, así que desde el portátil:

```bash
ssh -t -i ~/.ssh/servalillo deploy@LA_IP
cd /var/www/servalillo/server && php artisan servalillo:crear-usuario
```

O sin preguntas (para scripts): `--name=… --email=… --password=… --rol=administrador`.

**A partir del primer usuario, todo se crea desde la web** — el comando es solo el arranque:

| Quién | Dónde | Notas |
|---|---|---|
| Gestión / mantenimiento | panel **`/usuarios`** | roles `administrador` y `mantenimiento`; nunca lista choferes |
| Choferes | panel **`/chofers`** | crea `User` (rol `chofer`) + `Driver` en una transacción |
| Camiones, clientes, rutas | sus paneles | — |

El comando `servalillo:crear-usuario` **no** crea choferes (solo `administrador`/`mantenimiento`).

---

## 5. nginx + HTTPS (en el VPS)

```bash
sudo cp /var/www/servalillo/server/deploy/nginx.conf /etc/nginx/sites-available/servalillo
sudo sed -i 's/servalillo.example.com/TU_DOMINIO/; s#/var/www/servalillo#/var/www/servalillo#' /etc/nginx/sites-available/servalillo
sudo ln -sf /etc/nginx/sites-available/servalillo /etc/nginx/sites-enabled/servalillo
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d TU_DOMINIO        # añade el 443 + redirect y programa la renovación
```

---

## 6. Worker de colas + scheduler + backup (en el VPS)

```bash
cd /var/www/servalillo/server/deploy
sudo cp servalillo-worker.service servalillo-backup.service servalillo-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now servalillo-worker.service

# rclone → R2 (remote llamado "r2")
rclone config      # n → r2 → s3 → Cloudflare → access key / secret / endpoint del token R2
# bucket de backups (puede ser el mismo de los albaranes o uno aparte):
rclone mkdir r2:servalillo-backups
sudo systemctl enable --now servalillo-backup.timer

# scheduler
crontab -l 2>/dev/null | grep -q 'schedule:run' || (crontab -l 2>/dev/null; cat crontab.txt) | crontab -
```

---

## 7. Cloudflare R2 (bucket de ficheros)

1. Cloudflare → **R2** → **Create bucket** `servalillo` (privado).
2. **Manage R2 API Tokens** → crea uno con permiso *Object Read & Write* sobre ese bucket.
3. En el `.env` del VPS: `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`,
   `R2_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com`, `R2_BUCKET=servalillo`, `R2_URL=` (vacío).
4. `php artisan config:cache`.

---

## 8. APK de producción

```bash
cd ~/VSC/gestion-servalillo/mobile
cp dart_define.production.example.json dart_define.production.json
nano dart_define.production.json     # SERVER_URL=https://TU_DOMINIO · ENROLMENT_SECRET = el del .env

export JAVA_HOME=~/tools/jdk-17.0.20.1+1
flutter build apk --release --dart-define-from-file=dart_define.production.json
```

Instala `build/app/outputs/flutter-apk/app-release.apk` en el móvil de cada camión. Concede
permisos (ubicación siempre + batería sin restricción + ajustes anti-cierre del fabricante, ver
`mobile/README.md`). En el panel: **Mantenimiento → Dispositivos** → asigna cada uno a su chofer.

---

## 9. Pasar de «por IP en http» a dominio + HTTPS

Cuando haya dominio (A-record → `2.28.64.188`, AAAA → IPv6):

```bash
# --- en el VPS ---
sudo sed -i 's/server_name 2.28.64.188;/server_name TU_DOMINIO;/' /etc/nginx/sites-available/servalillo
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d TU_DOMINIO          # añade 443 + redirect + renovación automática

cd /var/www/servalillo/server
sed -i 's|^APP_URL=.*|APP_URL=https://TU_DOMINIO|' .env
sed -i 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=true/' .env
php artisan config:cache
sudo systemctl reload php8.4-fpm
```

```bash
# --- en el portátil ---
sed -i 's/^DOMAIN=.*/DOMAIN=TU_DOMINIO/' server/deploy/deploy.env
```

`AppServiceProvider` fuerza `https` en las URLs generadas en cuanto `APP_URL` empieza por `https://`.
Recompila el APK release apuntando al dominio (§8).

---

## Día a día: redeploy

```bash
# en el portátil, tras fusionar develop → test → main
git checkout main
bash server/deploy/deploy.sh
```

---

## Copias de seguridad y restauración

- **Automático**: `servalillo-backup.timer` → `pg_dump | gzip` a `r2:servalillo-backups/db/` a las
  03:30, retención 14 días. Los PDF y firmas ya están en R2 (no hay que copiarlos aparte).
- **Manual**: `bash /var/www/servalillo/server/deploy/backup-db.sh` (en el VPS).
- **Restaurar la BD**:
  ```bash
  rclone copy r2:servalillo-backups/db/servalillo-db-XXXX.sql.gz /tmp/
  gunzip -c /tmp/servalillo-db-XXXX.sql.gz | sudo -u postgres psql servalillo
  ```

---

## Monitorización

- **UptimeRobot** (gratis): monitor HTTP(s) a `https://TU_DOMINIO/up` cada 5 min → avisa por email
  si se cae.
- Errores 5xx de la app: **Mantenimiento → Errores del sistema** (tabla `error_logs`).
- Worker: `sudo systemctl status servalillo-worker` · `journalctl -u servalillo-worker -f`.
- Backup: `systemctl list-timers servalillo-backup` · `journalctl -u servalillo-backup`.

---

## Troubleshooting

| Síntoma | Mirar |
|---|---|
| 502 Bad Gateway | `sudo systemctl status php8.4-fpm`; ruta del socket en `nginx.conf` (`/run/php/php8.4-fpm.sock`). |
| 500 en todo | `.env` mal (falta `APP_KEY`, DB); `php artisan config:clear && php artisan config:cache`; `storage/logs/laravel.log`. |
| Cambié código/`.env` y no surte efecto | `opcache.validate_timestamps=0` (prod): hay que `sudo systemctl reload php8.4-fpm`. `deploy.sh` ya lo hace; en cambios manuales, hazlo tú. |
| Login da "page expired" / 419 | `SESSION_SECURE_COOKIE=true` sobre http → la cookie no vuelve. Ponlo a `false` mientras no haya HTTPS. |
| 500 solo en páginas con HTML (`/up` va) | falta `public/build/manifest.json` — sube los assets (`deploy.sh` o `rsync server/public/build/`). |
| `Permission denied` en `storage/logs/*.log` al desplegar | logs creados por `www-data`; el `chmod` de `deploy.sh` los ignora (`|| true`). Los ACL por defecto ya dan grupo `www-data:rwX`. |
| 500 en TODO tras cambiar una vista (`.blade.php`) | `touch(): Utime failed` en el log: el caché de vistas compiladas lo creó `deploy` (dueño), y `www-data` no puede recompilar en caliente porque `touch()` con mtime explícito exige ser el propietario. Tras cualquier cambio de blade: `php artisan view:clear && php artisan view:cache` (como `deploy`, mismo dueño) + `sudo systemctl reload php8.4-fpm`. |
| Firma / PDF de albarán falla (`Class "League\Flysystem\AwsS3V3\..." not found`) | alguna `R2_*` del `.env` tiene el placeholder `???` en vez de vacío — `config/filesystems.php` hace `env('R2_ACCESS_KEY_ID') ? s3 : local`, y `"???"` es *truthy*. Déjalas vacías mientras no haya bucket real. |
| Firma / PDF falla con `Unable to create a directory` | el disco `r2` local (sin R2) crea carpetas nuevas; sin `'permissions'` en `config/filesystems.php` Flysystem las hace `0700` (solo el dueño) — `deploy` y `www-data` son usuarios distintos, así que quien NO creó la carpeta primero se queda fuera. Ya corregido (`'permissions' => ['dir' => ['private' => 0770...]]`); si aparece en carpetas viejas: `chmod -R 0770 storage/app/private/r2 && setfacl -R -m g:www-data:rwx -m mask::rwx storage/app/private/r2`. |
| Assets sin estilo / 404 en `/build/` | no se subió `public/build`; re-lanza `deploy.sh`; comprueba `rsync` en la salida. |
| Un componente nuevo se ve sin estilo (elementos "sueltos", mal colocados) aunque el resto de la web va bien | clases de Tailwind que solo aparecen en ese `.blade.php` nuevo y el CSS servido es de **antes** de crearlo — cualquier cambio en `resources/{css,js}` o blade con clases nuevas exige `npm run build` + `rsync public/build/` (deploy.sh lo hace solo; un deploy manual de solo PHP/`git pull` no). |
| Albarán se queda en `Failed` | worker parado (`systemctl status servalillo-worker`) o SMTP mal; `php artisan queue:failed`. |
| Enlaces `http://` en correos/PDF | `APP_URL` no es `https://…`, o falta `APP_ENV=production` (activa `URL::forceScheme`). |
| Hora de las tareas/fechas mal | `APP_TIMEZONE=Europe/Madrid` en `.env` + `php artisan config:cache`. |
| El APK no conecta | ¿es build **release**? (release solo habla HTTPS). ¿`SERVER_URL` con el dominio? ¿cert OK? |
| "Ruta eficiente" lenta o falla | OSRM demo saturado; usa el fallback local igualmente. Considerar autoalojar OSRM. |
| Añadiste un permiso nuevo (`RolePermissionSeeder::PERMISSIONS`) y el rol no ve la pantalla (403) aunque el código ya esté desplegado | `deploy.sh` solo hace `migrate --force`, **nunca** re-siembra roles/permisos. Tras cualquier cambio en `RolePermissionSeeder`: `php artisan db:seed --class="Database\Seeders\RolePermissionSeeder" --force` a mano en el VPS (idempotente, `findOrCreate`/`syncPermissions` — seguro repetirlo). |
