# Infraestructura compartida

Lo que usan todos los nodos: la red, las funciones de los instaladores y las pruebas de la defensa. La instalación de cada nodo está en su carpeta (ver el [README principal](../README.md)).

| Archivo | Para qué |
|---|---|
| `red.tailscale.env.example` | IPs reales de Tailscale de cada integrante (trabajo desde casa) |
| `red.env.example` | IPs del switch del aula (presentación final) |
| `red.env` | Copia de uno de los anteriores. **Debe ser igual en las cinco laptops.** No se sube a Git. |
| `comun.ps1` / `comun.sh` | Funciones de los instaladores de Windows y Linux |
| `pruebas/balanceo.sh`, `pruebas/balanceo.ps1` | Reparto de peticiones entre backend1 y backend2 |
| `pruebas/fallos.sh` | Guía paso a paso de las pruebas de fallo |
| `pruebas/e2e.py` | Prueba de integración de extremo a extremo contra el sistema en vivo (web, móvil, BD, FTP, auditoría y balanceo) |

## Puertos y quién puede conectarse

| Nodo | Puerto | Acepta conexiones solo de |
|---|---|---|
| Laptop 1 · Balanceador | 80 | Cualquier cliente de la red |
| Laptop 2 · Web | 8080 | Laptop 1 |
| Laptop 3 · Backend | 8001, 8002 | Laptop 1 |
| Laptop 4 · PostgreSQL | 5432 | Laptop 3 |
| Laptop 5 · FTP | 2121, 60000-60100 | Laptop 3 |

Cada instalador crea estas reglas en el firewall de Windows (o `ufw` en Linux). Por eso, por ejemplo, desde la laptop de la web no se puede entrar a la base de datos: es el requisito "base de datos accesible únicamente desde los componentes autorizados".

## Cambiar de casa (Tailscale) al aula (switch)

Reemplaza `red.env` en las cinco laptops por el otro ejemplo y vuelve a ejecutar el instalador de cada nodo. También hay que actualizar:

- En `backend-api/.env`: `DB_HOST`, `FTP_HOST` y `TRUSTED_PROXIES`.
- En `servidor_ftp/.env`: `FTP_ALLOWED_IPS`.
- En `frontend/.env`: `EXPO_PUBLIC_API_URL`.

## Verificación

```bash
curl -i http://100.102.99.114/api/health
```

```json
{"estado":"ok","instancia":"backend1","servicios":{"base_datos":{"estado":"ok","latencia_ms":8},"archivos":{"estado":"ok","latencia_ms":7}}}
```

La cabecera `X-Backend-Instance` indica qué instancia respondió.

Prueba completa de extremo a extremo (crea un paciente de prueba en cada ejecución):

```bash
DOC_PASS='contraseña de la doctora' python3 infraestructura/pruebas/e2e.py http://100.102.99.114
```

## Comportamiento ante fallos

| Falla | Qué pasa | Cómo se detecta |
|---|---|---|
| Cae una instancia del backend | NGINX la aparta 15 s y envía todo a la otra; las peticiones GET que fallan al conectar se reintentan. POST/PUT/DELETE no se reintentan, para no duplicar citas ni pagos. | `clinica_error.log` (Laptop 1); `upstream=` en `clinica_access.log` |
| Caen las dos instancias | 503 en JSON con `Retry-After`; la web sigue cargando | `pruebas/balanceo.sh` |
| Cae el servidor de archivos | Todo funciona salvo subir y ver archivos, que responden 503 con mensaje claro | `/api/health` → `"degradado"` |
| Cae la base de datos | 503 en menos de 1 s (o en 3 s si el equipo no contesta), sin bloquear las instancias | `/api/health` → `"error"` |
| IP o puerto mal configurado | Igual que la caída del servicio correspondiente | `/api/health` y el log de Laravel, con la instancia y el `request_id` |

## Decisión: servicios sin contenedores

El equipo decidió no usar contenedores. Las bases (sección 2, Laptop 3) permiten "dos instancias o contenedores". Las dos instancias del backend son procesos PHP independientes, cada uno con su puerto, su ventana y su log, y se reinician solos si se caen. Detener una no afecta a la otra.

> La sección 4 de las bases menciona la contenerización del backend como obligatoria; esta decisión hay que justificarla ante el docente.
