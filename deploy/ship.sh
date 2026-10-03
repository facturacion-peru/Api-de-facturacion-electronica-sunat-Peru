#!/usr/bin/env bash
# Sube un paquete al servidor y lo publica con release.sh (lo usa GitHub Actions).
# Hay una copia idéntica en el repositorio del frontend (deploy/ship.sh).
#
# Uso: ship.sh <production|staging> <api|web> <paquete.tar.gz> [release.sh]
#
# Sin el cuarto argumento se usa el release.sh de la API ya publicada en el
# servidor (el frontend no lo tiene): la API se despliega antes que la web.
#
# Variables (secretos del ambiente en GitHub): DEPLOY_HOST, DEPLOY_USER,
# DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS; y HEALTH_URL, que release.sh comprueba
# después de publicar (si falla, vuelve a la versión anterior).
# shellcheck disable=SC2029 # las órdenes remotas se arman aquí a propósito (con printf %q)
set -euo pipefail

ENVIRONMENT="${1:?Uso: ship.sh <production|staging> <api|web> <paquete.tar.gz> [release.sh]}"
PART="${2:?Falta la parte: api o web}"
ARTIFACT="${3:?Falta el paquete .tar.gz}"
RELEASE_SH="${4:-}"
: "${DEPLOY_HOST:?Falta DEPLOY_HOST}" "${DEPLOY_USER:?Falta DEPLOY_USER}"
: "${DEPLOY_SSH_KEY:?Falta DEPLOY_SSH_KEY}" "${DEPLOY_KNOWN_HOSTS:?Falta DEPLOY_KNOWN_HOSTS}"
: "${HEALTH_URL:?Falta HEALTH_URL}"

case "$ENVIRONMENT" in production | staging) ;; *) echo "Ambiente desconocido: ${ENVIRONMENT}" >&2; exit 2 ;; esac
case "$PART" in api | web) ;; *) echo "Parte desconocida: ${PART}" >&2; exit 2 ;; esac
[ -f "$ARTIFACT" ] || { echo "No existe el paquete ${ARTIFACT}" >&2; exit 2; }

SECRETS="$(mktemp -d)"
trap 'rm -rf "$SECRETS"' EXIT
printf '%s\n' "$DEPLOY_SSH_KEY" > "${SECRETS}/key"
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" > "${SECRETS}/known_hosts"
chmod 600 "${SECRETS}/key"
SSH_OPTS=(-i "${SECRETS}/key" -o "UserKnownHostsFile=${SECRETS}/known_hosts" -o StrictHostKeyChecking=yes -o BatchMode=yes)
TARGET="${DEPLOY_USER}@${DEPLOY_HOST}"

INCOMING="incoming/${ENVIRONMENT}-${PART}-$(date -u +%Y%m%d%H%M%S)"
PACKAGE="${INCOMING}/${PART}.tar.gz"
if [ -n "$RELEASE_SH" ]; then
    SCRIPT="${INCOMING}/release.sh"
else
    SCRIPT="/srv/sunat/${ENVIRONMENT}/api/current/deploy/release.sh"
fi

echo "==> Subiendo ${PART} a ${ENVIRONMENT}"
ssh "${SSH_OPTS[@]}" "$TARGET" "mkdir -p $(printf '%q' "$INCOMING")"
scp "${SSH_OPTS[@]}" -q "$ARTIFACT" "${TARGET}:${PACKAGE}"
[ -n "$RELEASE_SH" ] && scp "${SSH_OPTS[@]}" -q "$RELEASE_SH" "${TARGET}:${SCRIPT}"

echo "==> Publicando"
health="curl -fsS -o /dev/null --max-time 10 --retry 3 --retry-delay 3 --retry-all-errors ${HEALTH_URL}"
remote="HEALTH_CMD=$(printf '%q' "$health") bash $(printf '%q' "$SCRIPT") ${ENVIRONMENT} ${PART} $(printf '%q' "$PACKAGE")"
remote+="; status=\$?; rm -rf $(printf '%q' "$INCOMING"); exit \$status"
ssh "${SSH_OPTS[@]}" "$TARGET" "$remote"
