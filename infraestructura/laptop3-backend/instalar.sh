#!/usr/bin/env bash
# Laptop 3: instala el backend Laravel y levanta 2 instancias PHP-FPM (backend1 y backend2).
# Uso: sudo ./instalar.sh   (desde infraestructura/laptop3-backend)
#
# Antes: copiar backend-api/.env.example a backend-api/.env y completar
# DB_*, FTP_*, APP_KEY (se genera si falta) y TRUSTED_PROXIES.
source "$(dirname "$0")/../comun.sh"
requiere_root
cargar_red

DIR="$(cd "$(dirname "$0")" && pwd)"
ORIGEN="${ORIGEN:-$DIR/../../backend-api}"

apt-get install -y php-fpm php-cli php-pgsql php-mbstring php-xml php-curl php-zip php-bcmath php-ftp unzip composer gettext-base rsync

PHP_FPM_BIN="$(ls /usr/sbin/php-fpm[0-9]* | sort -V | tail -n1)"
export PHP_FPM_BIN
echo "Usando $PHP_FPM_BIN"

# El PHP-FPM de la distribución no se usa: cada instancia tiene su propio servicio.
systemctl disable --now "$(basename "$PHP_FPM_BIN")" 2>/dev/null || true

# Código en BACKEND_DIR (misma ruta que usa NGINX en la Laptop 1).
mkdir -p "$BACKEND_DIR"
rsync -a --delete --exclude .env --exclude storage --exclude vendor --exclude database/database.sqlite "$ORIGEN/" "$BACKEND_DIR/"
rsync -a --ignore-existing "$ORIGEN/storage/" "$BACKEND_DIR/storage/"
if [[ ! -f "$BACKEND_DIR/.env" ]]; then
    cp "$ORIGEN/.env" "$BACKEND_DIR/.env" 2>/dev/null || {
        echo "Falta backend-api/.env (copiar desde .env.example y completar)." >&2
        exit 1
    }
fi

cd "$BACKEND_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

chown -R www-data:www-data storage bootstrap/cache
chmod 640 .env && chown root:www-data .env

# Como www-data, para que los logs y la caché queden con el dueño correcto.
# Sin config:cache: BACKEND_INSTANCE se lee del entorno de cada instancia.
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan migrate --force

mkdir -p /etc/clinica /var/log/clinica
chown www-data:www-data /var/log/clinica

for N in 1 2; do
    INSTANCIA="backend$N"
    PUERTO_VAR="BACKEND${N}_PORT"
    PUERTO="${!PUERTO_VAR}"
    export INSTANCIA PUERTO
    renderizar "$DIR/php-fpm-backend.conf.template" "/etc/clinica/backend$N.conf" '${INSTANCIA} ${PUERTO} ${BALANCEADOR_IP}'
    "$PHP_FPM_BIN" -t --fpm-config "/etc/clinica/backend$N.conf"
    permitir_puerto "$BALANCEADOR_IP" "$PUERTO"
done

renderizar "$DIR/clinica-backend@.service.template" /etc/systemd/system/clinica-backend@.service '${PHP_FPM_BIN}'
systemctl daemon-reload
systemctl enable --now clinica-backend@1 clinica-backend@2
systemctl restart clinica-backend@1 clinica-backend@2

echo
systemctl --no-pager --lines=0 status clinica-backend@1 clinica-backend@2
echo "Instancias escuchando en ${BACKEND_IP}:${BACKEND1_PORT} y ${BACKEND_IP}:${BACKEND2_PORT}"
