#!/usr/bin/env bash
#
# Despliegue de Gestión Servalillo. SE EJECUTA EN EL PORTÁTIL, no en el VPS.
#
#   bash server/deploy/deploy.sh            # normal (corre los tests antes)
#   SKIP_TESTS=1 bash server/deploy/deploy.sh
#
# Compila los assets en local, sube public/build por rsync y ejecuta el resto por SSH.
# El VPS no lleva Node.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

# --- Config (server/deploy/deploy.env, gitignored) -------------------------------------
ENV_FILE="server/deploy/deploy.env"
[ -f "$ENV_FILE" ] || { echo "Falta $ENV_FILE (copia deploy.env.example y rellénalo)."; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
: "${VPS:?VPS no definido en deploy.env}"
: "${APP_DIR:?APP_DIR no definido en deploy.env}"
: "${DOMAIN:?DOMAIN no definido en deploy.env}"

say() { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }

# --- 1. Rama main, árbol limpio, push --------------------------------------------------
branch="$(git rev-parse --abbrev-ref HEAD)"
[ "$branch" = "main" ] || { echo "Estás en '$branch', no en 'main'. Fusiona develop→test→main primero."; exit 1; }
git diff --quiet && git diff --cached --quiet || { echo "Hay cambios sin commitear."; exit 1; }
say "Push a origin/main"
git push origin main

# --- 2. Tests -------------------------------------------------------------------------
if [ "${SKIP_TESTS:-0}" != "1" ]; then
  say "Tests (docker compose exec laravel.test php artisan test)"
  ( cd server && docker compose exec -T laravel.test php artisan test )
fi

# --- 3. Compilar assets en local ----------------------------------------------------
say "npm ci && npm run build"
( cd server && npm ci --silent && npm run build )

# --- 4. git pull en el VPS ---------------------------------------------------------
say "git pull en el VPS"
ssh "$VPS" "cd '$APP_DIR' && git pull --ff-only"

# --- 5. Subir los assets compilados ----------------------------------------------
say "rsync public/build → VPS"
rsync -az --delete "server/public/build/" "$VPS:$APP_DIR/server/public/build/"

# --- 6. Migraciones + cachés + worker en el VPS -----------------------------------
# `php artisan up` se ejecuta siempre (aunque algo falle, no dejamos la web en mantenimiento).
say "composer / migrate / optimize / queue:restart en el VPS"
ssh "$VPS" "
  cd '$APP_DIR/server' || exit 1
  php artisan down --render=errors::503 --secret=deploy || true
  rc=0
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist &&
  php artisan migrate --force &&
  php artisan optimize &&
  { php artisan storage:link || true; } &&
  php artisan queue:restart &&
  sudo systemctl reload php8.4-fpm || rc=\$?
  php artisan up
  exit \$rc
"

# --- 7. Smoke check --------------------------------------------------------------
say "GET https://$DOMAIN/up"
curl -sf -o /dev/null -w '  → HTTP %{http_code}\n' "https://$DOMAIN/up"

say "Despliegue OK"
