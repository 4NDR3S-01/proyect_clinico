# Infraestructura y despliegue

Configuración de los cinco nodos del sistema distribuido (bases del proyecto, secciones 2, 4 y 6). Cada laptop ejecuta el script de su carpeta; todos leen las IPs y puertos de un único archivo `red.env`.

## Arquitectura

```
 Cliente web (navegador)      App móvil (Expo)
            │                        │
            └───────────┬────────────┘
                        ▼  http://<Laptop 1>/
            ┌──────────────────────────┐
            │ Laptop 1 · NGINX :80     │  reverse proxy + balanceador
            └──────┬─────────────┬─────┘
          /api/*   │             │  /  (web)
     FastCGI       ▼             ▼
 ┌─────────────────────────┐  ┌──────────────────────┐
 │ Laptop 3 · Backend      │  │ Laptop 2 · NGINX     │
 │ backend1 PHP-FPM :9001  │  │ frontend web :8080   │
 │ backend2 PHP-FPM :9002  │  └──────────────────────┘
 └──────┬──────────────┬───┘
        ▼              ▼
 ┌──────────────┐  ┌──────────────────┐
 │ Laptop 4     │  │ Laptop 5         │
 │ PostgreSQL   │  │ FTP :2121        │
 │ :5432        │  │ pasivos 60000+   │
 └──────────────┘  └──────────────────┘
```

| Nodo | Servicio | Puerto | Acepta conexiones de |
|---|---|---|---|
| Laptop 1 `192.168.1.10` | NGINX balanceador | 80 | Clientes (web y móvil) |
| Laptop 2 `192.168.1.20` | NGINX frontend web | 8080 | Solo Laptop 1 |
| Laptop 3 `192.168.1.30` | `clinica-backend@1` / `@2` (PHP-FPM) | 9001 / 9002 | Solo Laptop 1 |
| Laptop 4 `192.168.1.40` | PostgreSQL | 5432 | Solo Laptop 3 |
| Laptop 5 `192.168.1.50` | FTP (`clinica-ftp`) | 2121, 60000-60100 | Solo Laptop 3 |

## Red: LAN (switch) o Tailscale

En cada laptop, dentro de `infraestructura/`:

```bash
cp red.env.example red.env             # switch, IPs 192.168.1.x
# o
cp red.tailscale.env.example red.env   # Tailscale: poner la IP de cada laptop (tailscale ip -4)
```

El archivo debe ser **idéntico en las cinco laptops**. Para cambiar de modo se reemplaza `red.env` y se vuelve a ejecutar `instalar.sh` en cada nodo.

## Orden de instalación (Debian/Ubuntu)

1. **Laptop 4 · Base de datos**
   ```bash
   sudo DB_PASSWORD='contraseña-segura' laptop4-base-datos/instalar.sh
   ```
   Solo crea el usuario y la base; las tablas las crean las migraciones de Laravel.
2. **Laptop 5 · Archivos:** seguir `servidor_ftp/README.md` (rama `servidor-ftp`).
3. **Laptop 3 · Backend**
   ```bash
   cp backend-api/.env.example backend-api/.env   # completar DB_PASSWORD, FTP_PASSWORD, TRUSTED_PROXIES
   sudo laptop3-backend/instalar.sh
   ```
   Instala PHP, copia el código a `/opt/clinica/backend-api`, ejecuta las migraciones y levanta `clinica-backend@1` y `clinica-backend@2`.
4. **Laptop 2 · Web** (requiere Node.js 20+)
   ```bash
   sudo laptop2-web/instalar.sh
   ```
5. **Laptop 1 · Balanceador**
   ```bash
   sudo laptop1-balanceador/instalar.sh
   ```

### Clientes

- **Web:** `http://<Laptop 1>/`. Se compila con `VITE_API_BASE_URL=http://<Laptop 1>/api`.
- **Móvil:** en `frontend/.env`, `EXPO_PUBLIC_API_URL=http://<Laptop 1>/api`. Web y móvil usan la misma API y la misma base de datos.

## Variables de entorno

| Archivo | Contenido |
|---|---|
| `infraestructura/red.env` | IPs y puertos de los nodos |
| `backend-api/.env` | Conexión a PostgreSQL y FTP, `TRUSTED_PROXIES`, timeouts, logs |
| `servidor_ftp/.env` | Credenciales FTP e IPs autorizadas |
| `frontend-web/.env`, `frontend/.env` | URL del API (balanceador) |

Ningún `.env` se sube a Git; solo los `.env.example`.

## Verificación

```bash
curl -i http://192.168.1.10/api/health
```

```json
{"estado":"ok","instancia":"backend1","servicios":{"base_datos":{"estado":"ok","latencia_ms":2},"archivos":{"estado":"ok","latencia_ms":15}}}
```

Las cabeceras `X-Backend-Instance` y `X-Upstream` indican qué instancia respondió.

```bash
pruebas/balanceo.sh http://192.168.1.10 20    # reparto entre backend1 y backend2
pruebas/fallos.sh http://192.168.1.10         # guía de pruebas de fallo de la defensa
```

## Resiliencia y comportamiento ante fallos

| Falla | Qué pasa | Cómo se detecta |
|---|---|---|
| Cae una instancia del backend | NGINX la aparta 15 s y reenvía a la otra; GET que fallan al conectar se reintentan automáticamente. POST/PUT/DELETE no se reintentan, para no duplicar citas o pagos. | `clinica_error.log` (`connect() failed`), `upstream=` en `clinica_access.log` |
| Caen las dos instancias | NGINX responde 503 JSON con `Retry-After`; la web sigue cargando | `pruebas/balanceo.sh`, logs de NGINX |
| Cae el servidor de archivos | El sistema sigue funcionando; subir o ver archivos responde 503 con mensaje claro | `/api/health` → `"degradado"`, archivos `"caido"` |
| Cae la base de datos | Responde 503 en ~3 s (`DB_CONNECT_TIMEOUT`), sin bloquear las instancias | `/api/health` → `"error"`, log de Laravel |
| IP o puerto mal configurado | Igual que la caída del servicio correspondiente | `/api/health` + log con `instancia` y `request_id` |

## Logs

| Nodo | Ubicación |
|---|---|
| Laptop 1 | `/var/log/nginx/clinica_access.log` (instancia, estado y tiempo de cada petición), `clinica_error.log` |
| Laptop 2 | `/var/log/nginx/clinica_web_*.log` |
| Laptop 3 | `/opt/clinica/backend-api/storage/logs/laravel-*.log` (con `instancia` y `request_id`), `/var/log/clinica/backend*-fpm.log`, `journalctl -u clinica-backend@1` |
| Laptop 4 | `journalctl -u postgresql` (conexiones y consultas lentas > 1 s) |
| Laptop 5 | `servidor_ftp/logs/ftp.log`, `journalctl -u clinica-ftp` |

El `req_id` de NGINX se envía al backend como `X-Request-ID`, así una petición se puede seguir del balanceador al log de Laravel.

## Copias de seguridad

```bash
sudo laptop4-base-datos/respaldo.sh                      # crea /var/backups/clinica/clinica_<fecha>.dump
sudo laptop4-base-datos/respaldo.sh restaurar <archivo>  # restaura
```

## Decisión: servicios sin contenedores

El equipo decidió ejecutar todos los servicios directamente sobre el sistema operativo, gestionados con systemd, en lugar de contenedores. Las dos instancias del backend son procesos PHP-FPM independientes (`clinica-backend@1` y `@2`), cada una con su propio puerto, PID, logs y reinicio automático, por lo que detener una no afecta a la otra.

> Las bases del proyecto (sección 4) indican que la contenerización del backend es obligatoria. Esta decisión debe justificarse ante el docente; migrar las instancias a contenedores solo requeriría un `Dockerfile` del backend, sin cambiar NGINX ni el resto de nodos.
