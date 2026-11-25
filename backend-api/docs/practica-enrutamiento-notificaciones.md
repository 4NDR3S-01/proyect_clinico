# Práctica 3.2: enrutamiento y filtrado de notificaciones de citas

William Cabrera. Sigue sobre la práctica 3.1 (RabbitMQ, vhost `clinica`).

```
productor -> validación -> Message Filter -> Content-Based Router -> RabbitMQ -> consumidor | no_enrutables
```

## Contrato

```json
{
  "id": "NOT-0001",
  "tipo": "confirmacion",
  "prioridad": "alta",
  "correlation_id": "demo-0001",
  "datos": { "cita_id": "<uuid>", "canal": "correo" }
}
```

Obligatorios: `id`, `tipo`, `prioridad` (alta|media|baja), `datos.cita_id` (uuid), `datos.canal` (correo|sms).
`correlation_id` es opcional (si falta se usa `X-Correlation-ID` o se genera).

## Reglas

| Regla | Condición | Destino | Routing key |
|---|---|---|---|
| F1 | la cita existe, es cita médica y tiene paciente | (si no, filtrado) | - |
| F2 | la cita no ha pasado | (si no, filtrado) | - |
| F3 | confirmacion/recordatorio -> `programada`, cancelacion -> `cancelada` | (si no, filtrado) | - |
| R1 | tipo = confirmacion | confirmaciones | `notificacion.confirmacion` |
| R2 | tipo = recordatorio | recordatorios | `notificacion.recordatorio` |
| R3 | tipo = cancelacion | cancelaciones | `notificacion.cancelacion` |
| R0 | otro tipo | no_enrutables | `notificacion.no_enrutable` |

La prioridad (alta 9, media 5, baja 1) ordena los mensajes dentro de cada cola (`x-max-priority` 10).

Respuestas de `POST /api/enrutamiento/notificaciones` (doctora): 202 enrutado o no_enrutable,
200 filtrado, 422 inválido, 409 repetido, 503 error de transporte (broker caído).

## Ejecutar

```bash
php artisan migrate                                   # tabla mensajes_enrutados
php artisan notificaciones:consumir confirmaciones    # una terminal por destino
php artisan notificaciones:consumir recordatorios
php artisan notificaciones:consumir cancelaciones
php artisan notificaciones:consumir no_enrutables
```

- `GET /api/enrutamiento/reglas`: tabla de reglas.
- `GET /api/enrutamiento/notificaciones/{id}`: bitácora del mensaje (decisión, regla, destino, consumidor).
- Log: `storage/logs/enrutamiento.log`.
- Pruebas: `php artisan test --filter=EnrutamientoNotificacionesTest` (19).

El exchange, las colas y los enlaces los declaran el productor y cada consumidor al conectarse.
El envío del correo/SMS es simulado (`NOTIFICACION_LATENCIA_MS`); la entrega por RabbitMQ es real.
