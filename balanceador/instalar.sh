#!/usr/bin/env bash
# Laptop 1: instala y configura NGINX como reverse proxy y balanceador.
# Uso: sudo ./instalar.sh
source "$(dirname "$0")/../infraestructura/comun.sh"
requiere_root
cargar_red

apt-get install -y nginx gettext-base

DIR="$(dirname "$0")"
VARS='${BACKEND_IP} ${BACKEND1_PORT} ${BACKEND2_PORT} ${WEB_IP} ${WEB_PORT} ${BALANCEADOR_IP} ${SERVER_NAME}'

renderizar "$DIR/clinica.conf.template" /etc/nginx/sites-available/clinica.conf "$VARS"
renderizar "$DIR/clinica-backend.conf.template" /etc/nginx/snippets/clinica-backend.conf "$VARS"

ln -sf /etc/nginx/sites-available/clinica.conf /etc/nginx/sites-enabled/clinica.conf
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl enable nginx
systemctl reload nginx || systemctl restart nginx

permitir_puerto any 80

echo
echo "Balanceador listo en http://${SERVER_NAME}/"
echo "Comprueba: curl -i http://${SERVER_NAME}/api/health"
