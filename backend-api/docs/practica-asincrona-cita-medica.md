# Práctica 3.1: notificación de cita con RabbitMQ

Práctica individual de William Cabrera (IS-803, 2026-2). Cuando la doctora registra una cita, el aviso al paciente se saca de la petición HTTP: la API lo deja en una cola de RabbitMQ y un consumidor aparte lo procesa.

## Componentes

| Parte | Nombre |
|---|---|
| Productor | `POST /api/mensajeria/citas-medicas/notificaciones` (`NotificacionCitaController::asincrono`) |
| Exchange | `clinica.citas` (direct), routing key `notificacion.cita_registrada` |
| Cola | `clinica.citas.notificaciones` (durable; los rechazados van a `clinica.citas.dlx`) |
| Cola de fallidos | `clinica.citas.notificaciones.fallidas` |
| Consumidor | `php artisan citas:consumir-notificaciones` (`ConsumirNotificacionesCita`) |
| Estado | Tabla `notificaciones_cita`: `pendiente` → `procesando` → `enviada` (o `descartada`, `fallida`, `no_publicada`) |
| Log | `storage/logs/mensajeria.log` (JSON, una línea por evento, con `correlation_id`) |
| Versión síncrona | `POST /api/mensajeria/citas-medicas/notificaciones/sincrona` |
| Seguimiento | `GET /api/mensajeria/citas-medicas/notificaciones/{correlation_id}` |

Los tres endpoints exigen token de Sanctum y el rol `doctora`.

El envío al paciente es **simulado**: el consumidor arma el texto del aviso, espera `NOTIFICACION_LATENCIA_MS` (2000 ms por defecto) como lo haría un proveedor de correo o SMS, y guarda el resultado. No se envía nada a nadie.

## Mensaje

```json
{
  "message_id": "636b8395-9395-4937-b678-7a1fa078e56e",
  "correlation_id": "web-cita-demo-0001",
  "tipo": "notificacion_cita_registrada",
  "ocurrido_en": "2026-11-20T21:29:18.110Z",
  "payload": {
    "cita_id": "01a1fad7-fb56-73a5-83f6-3b2a889feae1",
    "codigo_paciente": "007",
    "fecha": "2026-11-30",
    "hora_inicio": "10:00",
    "canal": "correo"
  }
}
```

- `message_id`: este mensaje en concreto. El consumidor lo usa para no avisar dos veces si RabbitMQ entrega el mismo mensaje de nuevo.
- `correlation_id`: la operación completa. Si el cliente envía `X-Correlation-ID`, se respeta; si no, la API genera `cita-<uuid>`. Aparece en la respuesta, en las propiedades AMQP del mensaje, en el log y en la tabla.
- El payload no lleva nombre, cédula, teléfono ni correo. El consumidor busca el destino en la base y lo guarda enmascarado (`p***@example.test`).

## Qué pasa en cada caso

| Caso | Resultado |
|---|---|
| Datos válidos | 202, estado `pendiente`; el consumidor lo pasa a `enviada` y hace `ack` |
| Falta `cita_id`/`canal` o no son válidos | 422 con el error de cada campo; no se publica nada |
| RabbitMQ caído | 503 con `Retry-After: 30`; el registro queda `no_publicada` |
| Consumidor detenido | 202 igual; el mensaje espera en la cola (es durable y persistente) |
| Mensaje mal formado en la cola | El consumidor hace `reject(requeue=false)` y RabbitMQ lo pasa a la cola de fallidos |
| Error al procesar | Estado `fallida` con el motivo, `reject` y cola de fallidos (sin reintentos en bucle) |
| La cita se canceló antes de procesar | Estado `descartada`, `ack` |
| Mensaje repetido | Se ignora (`consumidor.duplicado_ignorado`) y se hace `ack` |
| El consumidor muere antes del `ack` | RabbitMQ devuelve el mensaje a la cola y lo toma el siguiente consumidor |
| Se pierde la conexión con RabbitMQ (broker reiniciado, red caída o laptop suspendida) | El consumidor registra `consumidor.conexion_perdida`, espera 5 s y se vuelve a conectar solo |

## Cómo ejecutarlo

1. Instalar RabbitMQ (en Kali/Debian: `sudo apt install rabbitmq-server` y `sudo rabbitmq-plugins enable rabbitmq_management`). Crear un vhost y un usuario para la aplicación:

   ```bash
   sudo rabbitmqctl add_vhost clinica
   sudo rabbitmqctl add_user clinica_app '<clave>'
   sudo rabbitmqctl set_permissions -p clinica clinica_app '.*' '.*' '.*'
   sudo rabbitmqctl set_user_tags clinica_app management
   ```

2. En `backend-api/.env`: `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_VHOST`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` y, si se quiere, `NOTIFICACION_LATENCIA_MS`.
3. `php artisan migrate` (crea `notificaciones_cita`).
4. Iniciar el consumidor en una terminal aparte: `php artisan citas:consumir-notificaciones`. Con `--max=N` termina después de N mensajes, y con Ctrl+C se detiene sin cortar el mensaje en curso.
5. Enviar desde Postman, con `Authorization: Bearer <token de la doctora>`:

   ```http
   POST /api/mensajeria/citas-medicas/notificaciones
   X-Correlation-ID: web-cita-demo-0001   (opcional)

   {"cita_id": "<uuid de una cita médica>", "canal": "correo"}
   ```

6. Revisar el estado con `GET /api/mensajeria/citas-medicas/notificaciones/{correlation_id}` y el log con `grep <correlation_id> storage/logs/mensajeria.log`.

### Verificar la cola

- Panel web: `http://127.0.0.1:15672` → *Queues* → vhost `clinica`.
- Terminal: `sudo rabbitmqctl list_queues -p clinica name messages_ready messages_unacknowledged consumers`.
- API del panel: `curl -u clinica_app:<clave> http://127.0.0.1:15672/api/queues/clinica`.

La topología (exchanges, colas y bindings) la declaran el productor y el consumidor al conectarse, así que no hay que crearla a mano.

## Pruebas

```bash
php artisan test --filter=NotificacionCitaAsincrona
```

Son 14 pruebas. 13 usan un publicador falso y no necesitan RabbitMQ. La de integración publica en una cola de prueba (`clinica.citas.notificaciones.test`), ejecuta el consumidor y comprueba que la cola queda vacía; si no encuentra el broker, se omite. Para que corra, hay que tener `RABBITMQ_USER` y `RABBITMQ_PASSWORD` en el entorno.

## Resultados medidos (laptop local, proveedor simulado de 2000 ms)

| | Síncrona | Asíncrona |
|---|---|---|
| Respuesta al cliente | 200 a los ~2037 ms, con el aviso ya enviado | 202 a los ~25-34 ms, con el aviso `pendiente` |
| Fin del procesamiento | El mismo momento de la respuesta | ~2 s después, en el consumidor |
| Si el proceso que envía no está | La petición falla o se queda esperando | La API responde igual; el mensaje espera en la cola |
| Seguimiento | Solo la respuesta HTTP | `correlation_id` en la respuesta, el mensaje, el log y la tabla |
