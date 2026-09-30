#!/usr/bin/env bash
# Vuelve a la versión anterior (spec 009). Uso: rollback.sh <production|staging> <api|web>
# Regla: las migraciones deben ser compatibles con la versión anterior (añadir antes de quitar).
set -euo pipefail

ENVIRONMENT="${1:?Uso: rollback.sh <production|staging> <api|web>}"
PART="${2:?Falta la parte: api o web}"
BASE="${BASE:-/srv/sunat}"
RELOAD_CMD="${RELOAD_CMD:-sudo /usr/bin/systemctl reload php8.5-fpm}"
ROOT="${BASE}/${ENVIRONMENT}/${PART}"

current="$(basename "$(readlink -f "${ROOT}/current")")"
target="$(find "${ROOT}/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | awk -v c="$current" '$0 < c' | head -1)"

[ -n "$target" ] || { echo "No hay una versión anterior a ${current}." >&2; exit 1; }

ln -sfn "${ROOT}/releases/${target}" "${ROOT}/current.tmp"
mv -Tf "${ROOT}/current.tmp" "${ROOT}/current"
[ "$PART" = api ] && $RELOAD_CMD
echo "==> ${ENVIRONMENT}/${PART}: de ${current} a ${target}."
