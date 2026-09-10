#!/usr/bin/env bash
#
# Copia de seguridad de la base de datos → Cloudflare R2. SE EJECUTA EN EL VPS
# (a mano o por servalillo-backup.timer, a diario).
#
# Solo la BD: los PDFs y firmas ya viven en R2 (disco `r2`).
#
# Requiere rclone configurado con un remote llamado `r2` apuntando al bucket de backups:
#   rclone config   → nombre: r2 · tipo: s3 · provider: Cloudflare
#   (access key / secret / endpoint del token de R2)
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/servalillo}"
REMOTE="${BACKUP_REMOTE:-r2:servalillo-backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Lee DB_* del .env de producción.
set -a; source <(grep -E '^DB_(DATABASE|USERNAME|PASSWORD|HOST|PORT)=' "$APP_DIR/server/.env"); set +a

STAMP="$(date +%Y%m%d-%H%M%S)"
FILE="$TMP/servalillo-db-$STAMP.sql.gz"

PGPASSWORD="$DB_PASSWORD" pg_dump \
  -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" \
  --no-owner --no-privileges --clean --if-exists \
  "$DB_DATABASE" | gzip -9 > "$FILE"

rclone copy "$FILE" "$REMOTE/db/" --s3-no-check-bucket

# Retención: borra los volcados con más de KEEP_DAYS días.
rclone delete "$REMOTE/db/" --min-age "${KEEP_DAYS}d" --s3-no-check-bucket || true

echo "Backup subido: $REMOTE/db/$(basename "$FILE")  ($(du -h "$FILE" | cut -f1))"
