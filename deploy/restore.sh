#!/usr/bin/env bash
# Restaura una copia (spec 009, A-45): base de datos y logos.
#
# Uso: BACKUP_ARCHIVE_PASSWORD=... restore.sh <copia.zip> <production|staging> [--yes]
#
# REEMPLAZA la base de datos del ambiente. Pon la aplicación en mantenimiento
# y ten a mano la APP_KEY del ambiente (sin ella los secretos SUNAT restaurados
# no se descifran). Procedimiento completo: deploy/RESTAURAR.md.
#
# Se puede apuntar a otra base o carpeta (restauración de prueba local):
#   ENV_FILE, APP_DIR, STORAGE_DIR, RESTORE_DB, SKIP_MAINTENANCE=1
set -euo pipefail

ZIP="${1:?Uso: restore.sh <copia.zip> <production|staging> [--yes]}"
ENVIRONMENT="${2:?Falta el ambiente}"
CONFIRM="${3:-}"
BASE="${BASE:-/srv/sunat}"
ENV_FILE="${ENV_FILE:-${BASE}/${ENVIRONMENT}/api/shared/.env}"
APP_DIR="${APP_DIR:-${BASE}/${ENVIRONMENT}/api/current}"
STORAGE_DIR="${STORAGE_DIR:-${BASE}/${ENVIRONMENT}/api/shared/storage/app}"
: "${BACKUP_ARCHIVE_PASSWORD:?Define BACKUP_ARCHIVE_PASSWORD (está en tu gestor de contraseñas)}"

[ -f "$ZIP" ] || { echo "No existe ${ZIP}" >&2; exit 2; }
[ -f "$ENV_FILE" ] || { echo "No existe ${ENV_FILE}" >&2; exit 2; }

env_value() { sed -n "s/^$1=\"\{0,1\}\([^\"]*\)\"\{0,1\}$/\1/p" "$ENV_FILE" | tail -1; }
DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"; DB_USER="$(env_value DB_USERNAME)"
DB_NAME="${RESTORE_DB:-$(env_value DB_DATABASE)}"
export PGPASSWORD; PGPASSWORD="$(env_value DB_PASSWORD)"

if [ "$CONFIRM" != --yes ]; then
    read -r -p "Esto REEMPLAZA la base «${DB_NAME}» y los logos de ${ENVIRONMENT}. Escribe el nombre de la base para seguir: " answer
    [ "$answer" = "$DB_NAME" ] || { echo "Cancelado."; exit 1; }
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==> Descifrando la copia"
# shellcheck disable=SC2016 # el código PHP va literal entre comillas simples
php -r '
    $zip = new ZipArchive();
    if ($zip->open($argv[1]) !== true) { fwrite(STDERR, "Copia dañada\n"); exit(1); }
    $zip->setPassword(getenv("BACKUP_ARCHIVE_PASSWORD"));
    if (! $zip->extractTo($argv[2])) { fwrite(STDERR, "No se pudo descifrar: revisa BACKUP_ARCHIVE_PASSWORD\n"); exit(1); }
' "$ZIP" "$WORK"

DUMP="$(find "${WORK}/db-dumps" -name '*.sql' | head -1)"
[ -n "$DUMP" ] || { echo "La copia no incluye el volcado de la base." >&2; exit 3; }

[ "${SKIP_MAINTENANCE:-0}" = 1 ] || (cd "$APP_DIR" && php artisan down --retry=60)

echo "==> Restaurando la base «${DB_NAME}»"
psql -q -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
    -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
psql -q -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -f "$DUMP" >/dev/null

if [ -d "${WORK}/public" ]; then
    echo "==> Restaurando los logos"
    mkdir -p "${STORAGE_DIR}/public"
    cp -a "${WORK}/public/." "${STORAGE_DIR}/public/"
fi

[ "${SKIP_MAINTENANCE:-0}" = 1 ] || (cd "$APP_DIR" && php artisan up)
echo "==> Restauración terminada. Comprueba el acceso y el estado SUNAT de una empresa."
