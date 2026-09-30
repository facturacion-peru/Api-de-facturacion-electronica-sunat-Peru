#!/usr/bin/env bash
# Pruebas de release.sh y rollback.sh sobre una carpeta temporal (spec 009, T021).
# Las órdenes con efectos se reemplazan: PHP, recarga de PHP-FPM y salud.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export BASE="${TMP}/srv" PHP_BIN=true RELOAD_CMD=true KEEP=5
failures=0

ok() { printf 'OK    %s\n' "$1"; }
fail() { printf 'FALLA %s\n' "$1"; failures=$((failures + 1)); }
check() { if eval "$2"; then ok "$1"; else fail "$1"; fi; }

artifact() { # paquete con un archivo que dice qué versión es
    mkdir -p "${TMP}/pkg" && echo "$1" > "${TMP}/pkg/VERSION"
    tar -czf "${TMP}/$1.tar.gz" -C "${TMP}/pkg" .
    echo "${TMP}/$1.tar.gz"
}
env_file() { mkdir -p "${BASE}/staging/api/shared/storage" && printf '%s\n' "$@" > "${BASE}/staging/api/shared/.env"; }
current() { cat "${BASE}/staging/$1/current/VERSION"; }
release() { bash "${HERE}/release.sh" staging "$1" "$2" >/dev/null 2>&1; }

env_file 'APP_ENV=local' 'APP_DEBUG=false'
check "aborta con APP_ENV=local (cuentas de prueba)" "! release api \"$(artifact v0)\" && [ ! -e \"${BASE}/staging/api/current\" ]"

env_file 'APP_ENV=staging' 'APP_DEBUG=true'
check "aborta con APP_DEBUG=true" "! release api \"$(artifact v0)\""

env_file 'APP_ENV="staging"' 'APP_DEBUG=false'
check "publica la API y enlaza .env y storage" "release api \"$(artifact v1)\" && [ \"\$(current api)\" = v1 ] && [ -L \"${BASE}/staging/api/current/.env\" ] && [ -L \"${BASE}/staging/api/current/storage\" ]"

sleep 1
check "una versión nueva reemplaza a la anterior" "release api \"$(artifact v2)\" && [ \"\$(current api)\" = v2 ]"

sleep 1
check "si falla la salud vuelve a la versión anterior y borra la fallida" "! HEALTH_CMD=false release api \"$(artifact v3)\" && [ \"\$(current api)\" = v2 ] && [ \"\$(find \"${BASE}/staging/api/releases\" -mindepth 1 -maxdepth 1 -type d | wc -l)\" -eq 2 ]"

for n in 4 5 6 7 8; do sleep 1; release web "$(artifact "w$n")"; done
check "la web conserva solo las últimas 5 versiones" "[ \"\$(find \"${BASE}/staging/web/releases\" -mindepth 1 -maxdepth 1 -type d | wc -l)\" -eq 5 ] && [ \"\$(current web)\" = w8 ]"

check "rollback vuelve a la versión anterior" "bash \"${HERE}/rollback.sh\" staging web >/dev/null && [ \"\$(current web)\" = w7 ]"

check "rollback sin versión anterior falla" "for _ in 1 2 3 4; do bash \"${HERE}/rollback.sh\" staging web >/dev/null 2>&1 || true; done; ! bash \"${HERE}/rollback.sh\" staging web >/dev/null 2>&1"

check "rechaza un ambiente desconocido" "! bash \"${HERE}/release.sh\" local api \"$(artifact v9)\" >/dev/null 2>&1"

if [ "$failures" -eq 0 ]; then
    echo "Todas las pruebas de despliegue pasaron."
else
    echo "${failures} prueba(s) fallaron."
    exit 1
fi
