#!/usr/bin/env bash
# Guía interactiva de las pruebas de fallo de la defensa (sección 6 de las bases).
# Se ejecuta desde cualquier laptop con acceso al balanceador.
# Uso: ./fallos.sh [URL_BALANCEADOR]
set -uo pipefail

URL="${1:-http://192.168.1.10}"

estado() {
    echo "--- GET $URL/api/health"
    curl -s -i --max-time 10 "$URL/api/health" | grep -iE '^HTTP|^X-Backend-Instance|^\{' || echo "(sin respuesta)"
    echo
}

paso() {
    echo
    echo "=============================================================="
    echo "$1"
    echo "=============================================================="
    echo "$2"
    read -rp "Presiona Enter cuando esté hecho..."
}

echo "Estado inicial (todo debe estar en \"ok\"):"
estado

paso "1. Caída de una instancia del backend" \
     "En la Laptop 3: sudo systemctl stop clinica-backend@2"
"$(dirname "$0")/balanceo.sh" "$URL" 6
echo "Esperado: todas atendidas por backend1. NGINX lo registra en /var/log/nginx/clinica_error.log."
paso "   Recuperación" "En la Laptop 3: sudo systemctl start clinica-backend@2"
"$(dirname "$0")/balanceo.sh" "$URL" 6

paso "2. Caída del servidor de archivos" \
     "En la Laptop 5: sudo systemctl stop clinica-ftp"
estado
echo "Esperado: \"estado\": \"degradado\" y archivos \"caido\". Login, citas y pacientes siguen funcionando;"
echo "subir o ver fotos/radiografías responde 503 con un mensaje claro."
paso "   Recuperación" "En la Laptop 5: sudo systemctl start clinica-ftp"
estado

paso "3. Caída de la base de datos" \
     "En la Laptop 4: sudo systemctl stop postgresql"
estado
echo "Esperado: HTTP 503 \"estado\": \"error\" en pocos segundos (DB_CONNECT_TIMEOUT), sin colgarse."
paso "   Recuperación" "En la Laptop 4: sudo systemctl start postgresql"
estado

paso "4. Dirección o puerto inválido" \
     "En la Laptop 3: cambiar DB_PORT=5433 en /opt/clinica/backend-api/.env y ejecutar sudo systemctl restart clinica-backend@1 clinica-backend@2"
estado
echo "Diagnóstico: /api/health muestra base_datos \"caido\" y el log de Laravel"
echo "(/opt/clinica/backend-api/storage/logs/) registra el error de conexión con la instancia y el request_id."
paso "   Recuperación" "Restaurar DB_PORT=5432 y reiniciar las dos instancias."
estado

echo "Pruebas de fallo terminadas."
