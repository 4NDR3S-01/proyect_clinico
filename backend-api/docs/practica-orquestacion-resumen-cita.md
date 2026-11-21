# Práctica 3.3: orquestador del resumen de cita

Aporte de William Cabrera al flujo de orquestación del equipo. La web (aporte de Jereny Vera, rama `frontend-web`, `docs/practica-3-3-orquestacion-frontend-hache.md`) muestra en Agenda el botón **Consultar resumen de cita** y llama a `GET /api/resumen-cita/{id}`. Este endpoint es el orquestador: consulta el servicio de citas y luego el de pacientes, y compone la respuesta que espera la web.

## Secuencia

1. La web envía `GET /api/resumen-cita/{id}` con `Authorization: Bearer <token>` (y `X-Correlation-ID` si lo tiene).
2. NGINX (Laptop 1) reparte la petición entre backend1 y backend2 (Laptop 3).
3. El orquestador llama al **servicio de citas**: `GET {ORQUESTACION_CITAS_URL}/agenda/{id}`, con el mismo token y el mismo `X-Correlation-ID`.
4. Con el `paciente_id` de la cita, llama al **servicio de pacientes**: `GET {ORQUESTACION_PACIENTES_URL}/pacientes/{paciente_id}`.
5. Valida las dos respuestas (que sea el mismo id, que sea una cita médica, que el paciente sea el de la cita) y devuelve solo lo que muestra la web.

El paso 4 depende del paso 3, porque necesita el `paciente_id`; por eso no van en paralelo.

## Contrato

```json
{
  "data": {
    "cita": { "id": "uuid", "fecha": "2026-11-27", "hora_inicio": "09:00", "hora_fin": "09:30",
              "estado": "programada", "descripcion": "Limpieza dental" },
    "paciente": { "codigo_paciente": "007", "nombres": "Paciente Ficticio A" }
  },
  "correlation_id": "resumen-..."
}
```

No se devuelven la cédula, el teléfono ni la dirección, aunque el servicio de pacientes los entregue.

| Caso | Respuesta |
|---|---|
| Todo bien | 200 con `data.cita` y `data.paciente` |
| La cita no tiene paciente registrado (reserva a nombre de alguien) | 200 con `data: null` |
| La cita no existe o es un registro personal | 404 |
| Un servicio rechaza el token o el permiso | 401 / 403, el mismo código |
| Un servicio responde algo que no cumple el contrato | 502 |
| Un servicio no responde, se agota el tiempo o da 5xx | 503 con `Retry-After: 10` y `servicio` (`citas` o `pacientes`) |
| Sin token / rol distinto de doctora | 401 / 403 (antes de llamar a nadie) |

Todas las respuestas llevan la cabecera `X-Correlation-ID`. Los tiempos máximos son 2 s para conectar y 3 s para responder, por servicio.

## Rutas, puertos y dependencias

| Componente | Ruta | Nodo y puerto | Depende de |
|---|---|---|---|
| Web (React) | Agenda > Consultar resumen de cita | Laptop 2, `:8080` | Orquestador |
| Balanceador NGINX | `/api/` | Laptop 1, `:80` | backend1 y backend2 |
| Orquestador | `GET /api/resumen-cita/{id}` | Laptop 3, `:8001` y `:8002` | Servicio de citas y servicio de pacientes |
| Servicio de citas | `GET /api/agenda/{id}` (rol doctora) | Laptop 3, detrás de NGINX | PostgreSQL |
| Servicio de pacientes | `GET /api/pacientes/{id}` (rol doctora o asistente) | Laptop 3, detrás de NGINX | PostgreSQL |
| PostgreSQL | esquemas `citas` y `clinica` | Laptop 4, `:5433` | — |

Variables en `backend-api/.env`:

| Variable | Valor en el despliegue |
|---|---|
| `ORQUESTACION_CITAS_URL` | `http://100.102.99.114/api` (balanceador) |
| `ORQUESTACION_PACIENTES_URL` | `http://100.102.99.114/api` (balanceador) |
| `ORQUESTACION_CONNECT_TIMEOUT` / `ORQUESTACION_TIMEOUT` | `2` / `3` segundos |

## Cómo probarlo

Automáticas (los servicios se simulan con `Http::fake`):

```bash
cd backend-api
php artisan test --filter=OrquestacionResumenCita
```

Con servicios reales en una sola laptop: la misma aplicación en tres puertos, sobre una base de prueba.

```bash
BACKEND_INSTANCE=servicio-citas     php artisan serve --port=8101
BACKEND_INSTANCE=servicio-pacientes php artisan serve --port=8102
BACKEND_INSTANCE=orquestador ORQUESTACION_CITAS_URL=http://127.0.0.1:8101/api \
  ORQUESTACION_PACIENTES_URL=http://127.0.0.1:8102/api php artisan serve --port=8000
```

Luego `GET http://127.0.0.1:8000/api/resumen-cita/{id}` con el token de la doctora. Para la prueba de falla basta detener la instancia del puerto 8102: el orquestador responde 503 y la web muestra el error con el botón Reintentar. Al volver a iniciarla, el reintento funciona.

El log `storage/logs/orquestacion.log` guarda una línea por paso, con el `correlation_id`, el servicio, el código HTTP, la instancia que respondió (`X-Backend-Instance`) y la duración.

> `php artisan serve` atiende una petición a la vez. Si el orquestador apunta a su propio puerto, se queda esperándose a sí mismo hasta agotar el tiempo. Por eso los servicios van en puertos aparte, o se usa `PHP_CLI_SERVER_WORKERS=4`. En el despliegue no pasa, porque NGINX reparte entre dos instancias.
