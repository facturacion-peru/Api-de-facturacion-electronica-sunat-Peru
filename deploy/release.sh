#!/usr/bin/env bash
# Publica una versión sin tiempo de caída (spec 009, plan «Despliegue»).
#
# Uso: release.sh <production|staging> <api|web> <paquete.tar.gz>
#
# Carpetas: $BASE/<ambiente>/<parte>/{releases/<fecha>, current, shared}.
# Las órdenes con efectos se pueden reemplazar (pruebas en deploy/tests):
#   PHP_BIN, RELOAD_CMD, HEALTH_CMD, BASE, KEEP.
set -euo pipefail

ENVIRONMENT="${1:?Uso: release.sh <production|staging> <api|web> <paquete.tar.gz>}"
PART="${2:?Falta la parte: api o web}"
ARTIFACT="${3:?Falta el paquete .tar.gz}"
BASE="${BASE:-/srv/sunat}"
KEEP="${KEEP:-5}"
PHP_BIN="${PHP_BIN:-php}"
RELOAD_CMD="${RELOAD_CMD:-sudo /usr/bin/systemctl reload php8.5-fpm}"

case "$ENVIRONMENT" in production | staging) ;; *) echo "Ambiente desconocido: ${ENVIRONMENT}" >&2; exit 2 ;; esac
case "$PART" in api | web) ;; *) echo "Parte desconocida: ${PART}" >&2; exit 2 ;; esac
[ -f "$ARTIFACT" ] || { echo "No existe el paquete ${ARTIFACT}" >&2; exit 2; }

ROOT="${BASE}/${ENVIRONMENT}/${PART}"
SHARED="${ROOT}/shared"
RELEASE="${ROOT}/releases/$(date -u +%Y%m%d%H%M%S)"

if [ "$PART" = api ]; then
    # Guardia: nunca publicar con cuentas de prueba ni con depuración (RF-001).
    env_value() { sed -n "s/^$1=\"\{0,1\}\([^\"]*\)\"\{0,1\}$/\1/p" "${SHARED}/.env" | tail -1; }
    [ -f "${SHARED}/.env" ] || { echo "Falta ${SHARED}/.env" >&2; exit 3; }
    app_env="$(env_value APP_ENV)"
    app_debug="$(env_value APP_DEBUG)"
    [ "$app_env" = "$ENVIRONMENT" ] || { echo "APP_ENV es «${app_env}» y debería ser «${ENVIRONMENT}»: se aborta." >&2; exit 3; }
    [ "$app_debug" = false ] || { echo "APP_DEBUG debe ser false en ${ENVIRONMENT}: se aborta." >&2; exit 3; }
fi

switch_to() { # cambio atómico del enlace «current»
    ln -sfn "$1" "${ROOT}/current.tmp"
    mv -Tf "${ROOT}/current.tmp" "${ROOT}/current"
}

previous="$(readlink -f "${ROOT}/current" 2>/dev/null || true)"

echo "==> Versión nueva: ${RELEASE}"
mkdir -p "$RELEASE"
tar -xzf "$ARTIFACT" -C "$RELEASE"

if [ "$PART" = api ]; then
    ln -sfn "${SHARED}/.env" "${RELEASE}/.env"
    rm -rf "${RELEASE}/storage"
    ln -sfn "${SHARED}/storage" "${RELEASE}/storage"
    (
        cd "$RELEASE"
        "$PHP_BIN" artisan migrate --force --no-interaction
        "$PHP_BIN" artisan storage:link --force --no-interaction >/dev/null
        "$PHP_BIN" artisan config:cache --no-interaction
        "$PHP_BIN" artisan route:cache --no-interaction
        "$PHP_BIN" artisan view:cache --no-interaction
    )
fi

switch_to "$RELEASE"
[ "$PART" = api ] && $RELOAD_CMD

HEALTH_CMD="${HEALTH_CMD:-}"
if [ -n "$HEALTH_CMD" ] && ! $HEALTH_CMD; then
    echo "La prueba de salud falló: se vuelve a la versión anterior." >&2
    if [ -n "$previous" ] && [ -d "$previous" ]; then
        switch_to "$previous"
        [ "$PART" = api ] && $RELOAD_CMD
        rm -rf "$RELEASE"
    fi
    exit 4
fi

echo "==> Publicada. Se conservan las últimas ${KEEP} versiones."
find "${ROOT}/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | tail -n "+$((KEEP + 1))" \
    | while read -r old; do rm -rf "${ROOT}/releases/${old}"; done
