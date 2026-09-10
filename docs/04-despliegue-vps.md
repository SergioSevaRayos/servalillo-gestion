# Despliegue en producción (VPS)

Guía para poner Gestión Servalillo en un VPS único. Desarrollo sigue en Docker local; producción
es bare-metal (nginx + PHP-FPM + PostgreSQL), sin Docker y **sin Node** (los assets se compilan en
el portátil y se suben).

- **VPS**: Hetzner CX23 (2 vCPU / 4 GB / 40 GB) o CAX11 (ARM), **Ubuntu 24.04 LTS**.
- **Ficheros** (PDF de albarán + firmas): Cloudflare R2 (bucket privado).
- **Despliegue**: `server/deploy/deploy.sh`, se ejecuta **desde el portátil**.
- **Coste**: ~€6-9/mes (VPS + dominio; R2 gratis a esta escala).

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

Permisos de escritura para php-fpm (usuario `www-data`):

```bash
sudo chown -R deploy:www-data /var/www/servalillo/server/storage /var/www/servalillo/server/bootstrap/cache
sudo chmod -R g+rwX /var/www/servalillo/server/storage /var/www/servalillo/server/bootstrap/cache
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

## 4. Semilla y usuario admin (en el VPS)

```bash
cd /var/www/servalillo/server
php artisan db:seed --class=ProductionSeeder --force     # roles + tipos de reparto
php artisan servalillo:crear-usuario                     # el admin (rol administrador)
```

El resto (choferes, camiones, clientes) se da de alta desde la web una vez dentro.

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
| Assets sin estilo / 404 en `/build/` | no se subió `public/build`; re-lanza `deploy.sh`; comprueba `rsync` en la salida. |
| Albarán se queda en `Failed` | worker parado (`systemctl status servalillo-worker`) o SMTP mal; `php artisan queue:failed`. |
| Enlaces `http://` en correos/PDF | `APP_URL` no es `https://…`, o falta `APP_ENV=production` (activa `URL::forceScheme`). |
| Hora de las tareas/fechas mal | `APP_TIMEZONE=Europe/Madrid` en `.env` + `php artisan config:cache`. |
| El APK no conecta | ¿es build **release**? (release solo habla HTTPS). ¿`SERVER_URL` con el dominio? ¿cert OK? |
| "Ruta eficiente" lenta o falla | OSRM demo saturado; usa el fallback local igualmente. Considerar autoalojar OSRM. |
