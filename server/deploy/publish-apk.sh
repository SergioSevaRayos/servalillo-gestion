#!/usr/bin/env bash
#
# Compila la APK tracker de PRODUCCIÓN y la publica para descargarla desde la web
# (/dispositivos → "Descargar APK del tracker"). SE EJECUTA EN EL PORTÁTIL. Ver docs/24.
#
#   bash server/deploy/publish-apk.sh          # compila y sube al VPS
#   LOCAL=1 bash server/deploy/publish-apk.sh  # compila (con dart_define.json) y la deja en local
#   NO_BUILD=1 bash server/deploy/publish-apk.sh  # sube la última APK ya compilada
#
# La APK no va en git (~60 MB): vive en server/storage/app/private/apk/ (disco `local`) junto a
# tracker.json (versión, fecha, sha256). Se firma con la clave debug de ESTE portátil: para que
# los móviles puedan actualizarse encima sin desinstalar, compila siempre desde aquí.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

say() { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }

if [ "${LOCAL:-0}" = "1" ]; then
  DEFINES="mobile/dart_define.json"
else
  DEFINES="mobile/dart_define.production.json"
  ENV_FILE="server/deploy/deploy.env"
  [ -f "$ENV_FILE" ] || { echo "Falta $ENV_FILE (copia deploy.env.example y rellénalo)."; exit 1; }
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  : "${VPS:?VPS no definido en deploy.env}"
  : "${APP_DIR:?APP_DIR no definido en deploy.env}"
fi
[ -f "$DEFINES" ] || { echo "Falta $DEFINES."; exit 1; }

VERSION="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("APP_VERSION",""))' "$DEFINES")"
# versionCode de Android = el "+N" de `version:` en pubspec.yaml. La APK instalada lo compara con
# este para actualizarse sola: SÚBELO en cada versión nueva o los móviles no se actualizarán.
VERSION_CODE="$(sed -n 's/^version: *[^+]*+\([0-9]*\).*/\1/p' mobile/pubspec.yaml)"
[ -n "$VERSION_CODE" ] || { echo "No encuentro el versionCode (+N) en mobile/pubspec.yaml."; exit 1; }
APK="mobile/build/app/outputs/flutter-apk/app-release.apk"

if [ "${NO_BUILD:-0}" != "1" ]; then
  say "flutter build apk --release ($DEFINES, versión ${VERSION:-?}, versionCode $VERSION_CODE)"
  export JAVA_HOME="${JAVA_HOME:-$HOME/tools/jdk-17.0.20.1+1}"
  ( cd mobile && flutter build apk --release --dart-define-from-file="${DEFINES#mobile/}" )
fi
[ -f "$APK" ] || { echo "No existe $APK."; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
cp "$APK" "$STAGE/servalillo-tracker.apk"
SHA="$(sha256sum "$STAGE/servalillo-tracker.apk" | cut -d' ' -f1)"
printf '{"version":"%s","version_code":%s,"built_at":"%s","sha256":"%s"}\n' "$VERSION" "$VERSION_CODE" "$(date -Iseconds)" "$SHA" > "$STAGE/tracker.json"

if [ "${LOCAL:-0}" = "1" ]; then
  DEST="server/storage/app/private/apk"
  mkdir -p "$DEST"
  cp "$STAGE/servalillo-tracker.apk" "$STAGE/tracker.json" "$DEST/"
  say "Publicada en local ($DEST)"
else
  say "rsync → $VPS:$APP_DIR/server/storage/app/private/apk/"
  ssh "$VPS" "mkdir -p '$APP_DIR/server/storage/app/private/apk'"
  chmod 644 "$STAGE"/*
  rsync -az "$STAGE/servalillo-tracker.apk" "$STAGE/tracker.json" "$VPS:$APP_DIR/server/storage/app/private/apk/"
  ssh "$VPS" "chmod -R ug+rwX '$APP_DIR/server/storage/app/private/apk' 2>/dev/null || true"
  say "APK ${VERSION:-} publicada (sha256 ${SHA:0:12}…)"
fi
