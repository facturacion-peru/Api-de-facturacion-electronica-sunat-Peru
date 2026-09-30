#!/usr/bin/env bash
# Prepara un VPS Ubuntu 24.04 para el SaaS (spec 009, plan «Servidor»).
# Idempotente: se puede volver a ejecutar sin daño.
#
# Uso (como root, desde una copia de la carpeta deploy/):
#   DOMAIN=midominio.pe ADMIN_EMAIL=yo@midominio.pe ./provision.sh
#
# Requiere que el usuario root ya tenga tu llave SSH en authorized_keys.
set -euo pipefail

: "${DOMAIN:?Define DOMAIN (p. ej. midominio.pe)}"
: "${ADMIN_EMAIL:?Define ADMIN_EMAIL (avisos de vencimiento de los certificados HTTPS)}"
PHP_VERSION="${PHP_VERSION:-8.5}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
ENVIRONMENTS="${ENVIRONMENTS:-production staging}"
BASE="${BASE:-/srv/sunat}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '\n==> %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Ejecuta como root." >&2; exit 1; }

hosts_for() { # entorno → «api-host app-host»
    if [ "$1" = production ]; then echo "api.${DOMAIN} app.${DOMAIN}"; else echo "$1-api.${DOMAIN} $1-app.${DOMAIN}"; fi
}

log "Paquetes del sistema"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq software-properties-common ca-certificates curl unzip p7zip-full ufw fail2ban unattended-upgrades \
    nginx postgresql certbot python3-certbot-nginx
if ! apt-cache policy "php${PHP_VERSION}-fpm" | grep -q Candidate: || apt-cache policy "php${PHP_VERSION}-fpm" | grep -q 'Candidate: (none)'; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
fi
apt-get install -yq "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-gd" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-zip" "php${PHP_VERSION}-curl"

log "Actualizaciones de seguridad automáticas"
dpkg-reconfigure -f noninteractive unattended-upgrades

log "Usuario ${DEPLOY_USER} (solo llave SSH)"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"
if [ -s /root/.ssh/authorized_keys ] && [ ! -s "/home/${DEPLOY_USER}/.ssh/authorized_keys" ]; then
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys"
fi
# Solo puede recargar PHP-FPM con sudo (lo usa release.sh).
echo "${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm" > /etc/sudoers.d/sunat-deploy
chmod 440 /etc/sudoers.d/sunat-deploy
visudo -cf /etc/sudoers.d/sunat-deploy

log "SSH: sin contraseñas ni root"
if [ -s "/home/${DEPLOY_USER}/.ssh/authorized_keys" ]; then
    cat > /etc/ssh/sshd_config.d/90-sunat.conf <<CONF
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
CONF
    sshd -t && systemctl reload ssh
else
    echo "AVISO: ${DEPLOY_USER} no tiene llave SSH; no se desactiva el acceso de root para no dejarte fuera." >&2
fi

log "Firewall"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
systemctl enable --now fail2ban >/dev/null

log "PHP-FPM"
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/sunat.conf" <<CONF
[sunat]
user = ${DEPLOY_USER}
group = ${DEPLOY_USER}
listen = /run/php/sunat.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
CONF
cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/90-sunat.ini" <<CONF
opcache.enable=1
opcache.memory_consumption=128
opcache.validate_timestamps=0
expose_php=Off
upload_max_filesize=2M
post_max_size=3M
CONF
systemctl restart "php${PHP_VERSION}-fpm"

for env in $ENVIRONMENTS; do
    read -r api_host app_host <<<"$(hosts_for "$env")"

    log "Ambiente ${env}: carpetas"
    for part in api web; do
        install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" "${BASE}/${env}/${part}/releases" "${BASE}/${env}/${part}/shared"
    done
    for dir in app/public framework/cache framework/sessions framework/views logs; do
        install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" "${BASE}/${env}/api/shared/storage/${dir}"
    done

    log "Ambiente ${env}: base de datos"
    db="sunat_${env}"
    secret="/root/sunat-db-${env}.txt"
    if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${db}'" | grep -q 1; then
        password="$(openssl rand -hex 24)"
        sudo -u postgres psql -qc "CREATE ROLE ${db} LOGIN PASSWORD '${password}'"
        umask 077 && printf '%s\n' "$password" > "$secret"
        echo "Contraseña de ${db} guardada en ${secret}: cópiala al .env y a tu gestor de contraseñas."
    fi
    sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${db}'" | grep -q 1 \
        || sudo -u postgres createdb -O "$db" "$db"

    log "Ambiente ${env}: .env"
    env_file="${BASE}/${env}/api/shared/.env"
    if [ ! -f "$env_file" ]; then
        install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "${HERE}/env.${env}.example" "$env_file"
        sed -i "s/<dominio>/${DOMAIN}/g" "$env_file"
        [ -f "$secret" ] && sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=\"$(cat "$secret")\"|" "$env_file"
        echo "Completa los valores <entre ángulos> de ${env_file} antes del primer despliegue."
    fi

    log "Ambiente ${env}: Nginx"
    for part in api web; do
        host="$api_host"; [ "$part" = web ] && host="$app_host"
        sed -e "s/{{ENV}}/${env}/g" -e "s/{{HOST}}/${host}/g" "${HERE}/nginx/${part}.conf.template" \
            > "/etc/nginx/sites-available/sunat-${env}-${part}.conf"
        ln -sfn "/etc/nginx/sites-available/sunat-${env}-${part}.conf" "/etc/nginx/sites-enabled/sunat-${env}-${part}.conf"
    done

    log "Ambiente ${env}: scheduler cada minuto"
    echo "* * * * * ${DEPLOY_USER} cd ${BASE}/${env}/api/current && php artisan schedule:run >> /dev/null 2>&1" \
        > "/etc/cron.d/sunat-${env}"
done

rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

log "HTTPS (Let's Encrypt)"
domains=()
for env in $ENVIRONMENTS; do
    for host in $(hosts_for "$env"); do
        if getent hosts "$host" >/dev/null; then domains+=(-d "$host"); else echo "AVISO: ${host} aún no resuelve en DNS; se omite." >&2; fi
    done
done
if [ "${#domains[@]}" -gt 0 ]; then
    certbot --nginx --non-interactive --agree-tos --redirect -m "$ADMIN_EMAIL" "${domains[@]}"
fi

log "Listo. Siguiente: completar los .env y desplegar desde GitHub Actions."
