# Práctica: arquitectura reactiva y orientada a eventos — cita médica

Cuando una cita médica cambia (la paciente la solicita, cancela o pide reprogramar; la
doctora la programa o la completa), el backend **publica un evento** y los interesados
**reaccionan** sin preguntar: el panel web de la doctora se actualiza solo y una
bitácora asíncrona registra lo ocurrido.

Antes de esta práctica la web se enteraba de los cambios **consultando** el API
(petición–respuesta: «¿hay algo nuevo?»). Ahora el sistema **empuja** el cambio en cuanto
ocurre (≈0,5 s en la prueba), sin recargar la página.

---

## 1. Conceptos y dónde están en el proyecto

| Concepto | Qué es | Dónde está |
|---|---|---|
| **Evento de dominio** | Un hecho del negocio ya ocurrido, en pasado: «cita solicitada» | `app/Events/CitaCambioEstado.php` (8 tipos: `solicitada`, `programada`, `reprogramacion_solicitada`, `cancelada`, `completada`…) |
| **Productor** | Quien detecta el hecho y publica el evento | `app/Observers/AgendaObserver.php` (observa el modelo `Agenda`) |
| **Publicar / suscribir** | El productor no conoce a los consumidores; cada uno se suscribe por su cuenta | `app/Providers/AppServiceProvider.php` (`Event::listen`) |
| **Consumidor síncrono** | Difunde el evento por WebSocket | `app/Listeners/DifundirCambioDeCita.php` |
| **Consumidor asíncrono (cola)** | Escribe la bitácora sin hacer esperar al usuario | `app/Listeners/RegistrarEventoDeCita.php` (cola `eventos`, tabla `jobs`) |
| **Broker de mensajes / WebSocket** | Mantiene conexiones abiertas con los clientes y les reparte los mensajes | Laravel Reverb (`php artisan reverb:start`, puerto 8085) |
| **Canales privados** | Cada mensaje solo llega a quien tiene permiso | `routes/channels.php`: `clinica.doctora` y `paciente.{id}` |
| **Cliente reactivo** | Reacciona al evento: refresca datos y avisa | `frontend-web/src/features/realtime/` (Laravel Echo + pusher-js) |
| **Consistencia** | Solo se publica lo que de verdad quedó guardado | `ShouldHandleEventsAfterCommit` en el observador |
| **Resiliencia** | Si el broker cae, la operación no falla | `try/catch` en `DifundirCambioDeCita` y la cola reintenta la bitácora |

### Principios reactivos (Manifiesto Reactivo) aplicados

- **Responsivo:** la doctora ve la solicitud en menos de un segundo; la paciente recibe su respuesta (HTTP 201) sin esperar a la bitácora.
- **Resiliente:** si Reverb se cae, las citas se siguen guardando; si el worker se detiene, los eventos esperan en la cola.
- **Elástico:** las dos instancias del backend publican al mismo Reverb; no importa cuál atendió la petición (el aviso muestra «Procesado por backend1/backend2»).
- **Orientado a mensajes:** los componentes se comunican con mensajes asíncronos, sin conocerse entre sí.

---

## 2. Flujo

```
 App móvil (paciente)                                         Panel web (doctora)
        │ POST /api/agenda/solicitar-cita                               ▲  aviso + agenda
        ▼                                                               │  actualizada
 ┌──────────── NGINX (Laptop 1) ─────────────┐                          │
 │  /api/  → backend1 | backend2             │   /app/ (WebSocket) ─────┘
 └───────────────┬───────────────────────────┘          ▲
                 ▼                                       │
 ┌────────────── Laptop 3 ───────────────────────────────┼───────────┐
 │ AgendaService → Agenda::create() ──► PostgreSQL (L4)  │           │
 │                     │ (después del COMMIT)            │           │
 │                     ▼                                 │           │
 │              AgendaObserver ── evento CitaCambioEstado │           │
 │                     │                                 │           │
 │        ┌────────────┴─────────────┐                   │           │
 │        ▼                          ▼                   │           │
 │ DifundirCambioDeCita      RegistrarEventoDeCita       │           │
 │ (síncrono)                (cola "eventos")            │           │
 │        │ HTTP                     │                   │           │
 │        ▼                          ▼                   │           │
 │  Laravel Reverb :8085 ────────────┼───────────────────┘           │
 │                          queue:work → storage/logs/eventos.log    │
 └───────────────────────────────────────────────────────────────────┘
```

Mensaje que viaja por el WebSocket (evento `cita_actualizada`):

```json
{
  "evento_id": "9f3b9865-aba7-4f8a-9141-070ad277145a",
  "tipo": "solicitada",
  "cita_id": "01a1e95f-be51-7145-87cb-16b0a73d581d",
  "paciente_id": "01a1e95e-9edf-7149-9167-fe7d0b487b9b",
  "paciente_nombre": "Ana Pérez",
  "estado": "pendiente",
  "fecha": null,
  "hora_inicio": null,
  "instancia": "backend1",
  "ocurrido_en": "2026-11-17T06:58:48-05:00"
}
```

---

## 3. Preparación

### Opción A — en los cinco nodos

1. **red.env** (todas las laptops): agregar `REVERB_PORT=8085` y `REVERB_APP_KEY=clinica-clave` (ver `infraestructura/red.env.example`).
2. **Laptop 3 (backend)**:
   - En `backend-api\.env` copiar el bloque de Reverb de `.env.example`: `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8085`, `REVERB_SCHEME=http`, `REVERB_SERVER_PORT=8085`. Revisar que `QUEUE_CONNECTION=database`.
   - `composer install` (Reverb ya está en `composer.json`) y `.\instalar.ps1` (abre el puerto 8085 solo para el balanceador).
   - `.\iniciar.ps1` (las dos instancias) y **`.\eventos.ps1`** (Reverb y el worker, cada uno en su ventana).
3. **Laptop 1 (balanceador)**: `sudo ./balanceador/instalar.sh` para agregar el proxy de WebSockets `/app/` → Laptop 3:8085.
4. **Laptop 2 (web)**: `.\instalar.ps1` vuelve a compilar con `VITE_REVERB_*` (toma la clave de red.env).
5. Comprobar: en la web, con la sesión de la doctora, abajo a la derecha debe decir **● En vivo**.

### Opción B — en una sola laptop (ensayo)

```bash
# backend-api/  (BROADCAST_CONNECTION=reverb y REVERB_* en .env)
php artisan reverb:start --debug                     # terminal 1
php artisan queue:work --queue=eventos,default       # terminal 2
BACKEND_INSTANCE=backend1 php -S 127.0.0.1:8001 -t public   # terminal 3
BACKEND_INSTANCE=backend2 php -S 127.0.0.1:8002 -t public   # terminal 4

# frontend-web/  (CORS_ALLOWED_ORIGINS=http://localhost:5173 en el backend)
VITE_API_BASE_URL=http://127.0.0.1:8001/api VITE_REVERB_APP_KEY=clinica-clave \
VITE_REVERB_HOST=127.0.0.1 VITE_REVERB_PORT=8085 npm run dev
```

### Herramientas de la demostración

| Herramienta | Para qué |
|---|---|
| `infraestructura/pruebas/demo-eventos.sh [URL_API]` | Guion paso a paso: dispara solicitar → programar → reprogramar → cancelar e imprime el código HTTP y la instancia que atendió cada petición. |
| `frontend-web/scripts/escuchar-eventos.mjs [URL_API] [HOST] [PUERTO] [CLAVE]` | Oyente de terminal: se suscribe al mismo canal que el panel web e imprime cada evento al llegar. Útil si el proyector solo muestra una ventana. |

Con el balanceador: `URL_API=http://<balanceador>/api`, `HOST=<balanceador>`, `PUERTO=80`.
Credenciales por variables `PACIENTE_USUARIO`, `PACIENTE_PASSWORD`, `DOCTORA_USUARIO`, `DOCTORA_PASSWORD` (si faltan, se preguntan).
La paciente de prueba **no debe tener una solicitud pendiente** antes de empezar.

---

## 4. Ejecución manual en vivo (tú escribes cada comando)

El script `demo-eventos.sh` queda como respaldo. En la presentación conviene hacerlo a mano:
cada comando es un paso que se explica. Ventanas a tener abiertas:

| Ventana | Qué muestra |
|---|---|
| **A** — Reverb (Laptop 3) | El broker: conexiones que entran y mensajes que reparte |
| **B** — Worker de la cola (Laptop 3) | El consumidor asíncrono procesando cada evento |
| **C** — Navegador: web de la doctora en *Citas médicas*, con DevTools → Red → **WS** abierto | El cliente reactivo y los mensajes del WebSocket |
| **D** — Terminal tuya (o la app móvil de la paciente) | El productor: las acciones de la paciente |

### 4.1 Levantar las piezas (explicando cada una)

```powershell
# Ventana A (Laptop 3, backend-api): el broker de mensajes / servidor WebSocket
php artisan reverb:start --host=0.0.0.0 --port=8085 --debug
```
> «Reverb mantiene conexiones abiertas con los clientes. El backend le entrega los mensajes y él los reparte.»

```powershell
# Ventana B (Laptop 3, backend-api): el consumidor asíncrono
php artisan queue:work --queue=eventos,default --sleep=1
```
> «Este proceso toma trabajos de la cola `eventos`. Es independiente de quien atiende las peticiones.»

En la **ventana C**, iniciar sesión como doctora. Señalar **● En vivo** abajo a la derecha. En DevTools → Red → WS
aparece la conexión `/app/clinica-clave` y, en *Mensajes*, la suscripción a `private-clinica.doctora`.
En la ventana A se ve la conexión nueva.
> «La web se suscribió a un canal privado. Para entrar, el backend validó su token en `/api/broadcasting/auth`.»

### 4.2 Iniciar sesión como paciente (ventana D)

Si tienes la app móvil, úsala. Si no, con `curl` (Linux / Git Bash):

```bash
API=http://<balanceador>/api
TP=$(curl -s -X POST $API/auth/login -H 'Accept: application/json' -H 'Content-Type: application/json' \
     -d '{"username":"<paciente>","password":"<contraseña>"}' | python3 -c 'import json,sys;print(json.load(sys.stdin)["access_token"])')
echo $TP
```

### 4.3 Evento 1 — la paciente solicita una cita

```bash
curl -i -X POST $API/agenda/solicitar-cita -H 'Accept: application/json' -H "Authorization: Bearer $TP"
```

Qué mostrar, en orden:
1. **D:** `HTTP/1.1 201` y la cabecera `X-Backend-Instance` (qué instancia atendió). Copiar el `id` de `data`.
2. **C:** el aviso «… solicitó una cita» y el contador *Solicitudes pendientes* que sube **sin recargar**. En DevTools → WS, el mensaje `cita_actualizada` con su JSON.
3. **A:** Reverb registró el mensaje que repartió.
4. **B:** el worker procesó `RegistrarEventoDeCita … DONE`.
> «Un solo POST y tres reacciones. La paciente no sabe que existen el panel ni la bitácora.»

### 4.4 Evento 2 — la doctora programa (desde la web)

En la ventana C: pestaña *Solicitudes pendientes* → **Programar** → fecha y hora → guardar.
Se ve el evento `programada` en WS, en la ventana A y en la ventana B.
> «Ahora el productor es la doctora. El mismo evento va también al canal privado de la paciente.»

### 4.5 Evento 3 — la paciente cancela

```bash
CITA=<id copiado en 4.3>
curl -i -X PUT $API/agenda/mis-citas/$CITA/cancelar -H 'Accept: application/json' -H "Authorization: Bearer $TP"
```
Aviso «… canceló su cita» y la agenda se actualiza sola.

### 4.6 La bitácora (consumidor asíncrono)

```powershell
Get-Content storage\logs\eventos.log -Tail 3      # Linux: tail -n 3 storage/logs/eventos.log
```
> «Cada evento quedó registrado sin que la paciente esperara por ello.»

### 4.7 Resiliencia — el broker se cae

1. En la ventana A, **Ctrl+C**. En C el indicador cambia a **● Sin conexión en vivo**.
2. Repetir el comando de 4.3: sigue respondiendo **201** y la cita se guarda.
3. Mostrar que la ventana B **sí** procesó el evento y el log `storage/logs/laravel-<fecha>.log`:
   `No se pudo difundir el evento de la cita por WebSocket`.
4. Volver a ejecutar el comando de la ventana A: la web vuelve a **● En vivo** sola.
> «Falló el sistema de notificaciones, no el negocio: degradación controlada.»

(Para repetir la demo, la paciente no puede tener una solicitud pendiente: cancélala con 4.5.)

### 4.8 Mostrar el código (2 minutos)

| Archivo | Qué decir |
|---|---|
| `app/Observers/AgendaObserver.php` | El **productor**: traduce cambios del modelo en eventos, solo después del COMMIT. |
| `app/Events/CitaCambioEstado.php` | El **evento**: un hecho en pasado, con sus datos. |
| `app/Providers/AppServiceProvider.php` | **Publicar/suscribir**: dos `Event::listen`. Agregar un consumidor = una línea, sin tocar al productor. |
| `app/Listeners/DifundirCambioDeCita.php` | Consumidor **síncrono** con `try/catch` (resiliencia). |
| `app/Listeners/RegistrarEventoDeCita.php` | Consumidor **asíncrono**: `implements ShouldQueue`. |
| `routes/channels.php` | Seguridad de los **canales privados**. |
| `frontend-web/src/features/realtime/LiveAppointments.tsx` | El **cliente reactivo**: al llegar el evento, invalida la consulta y React vuelve a pintar. |

## 5. Guion resumido (versión automática, de respaldo)


Pantallas: **izquierda** = panel web de la doctora en *Citas médicas*; **derecha** = terminal
con el oyente y otra con `demo-eventos.sh` (o la app móvil de la paciente).

### Paso 0 — Contexto (1 min)
> «Antes, la web preguntaba al servidor cada cierto tiempo si había citas nuevas. Eso es
> *pull*: llega tarde y gasta peticiones aunque no haya cambios. Ahora el backend publica
> un evento en cuanto la cita cambia y los clientes reaccionan: es *push*.»

Mostrar el indicador **● En vivo** y la terminal del oyente: «suscrito a private-clinica.doctora».

### Paso 1 — La paciente solicita una cita (productor → consumidores)
Ejecutar el paso 1 del script (o «Solicitar cita» en la app móvil).

- **Se ve:** aviso «Ana Pérez solicitó una cita · Procesado por backend1», el contador de *Solicitudes pendientes* sube **sin recargar**, el oyente imprime `EVENTO cita.solicitada`.
- **Decir:** «La app solo hizo un POST. No sabe que existe el panel ni la bitácora: eso es el **desacoplamiento** de publicar/suscribir.»

### Paso 2 — La doctora programa la cita
Paso 2 del script (o «Programar» en la web).

- **Se ve:** `cita.programada` con fecha y hora.
- **Decir:** «El mismo evento va también al canal privado `paciente.{id}`: la app de la paciente podría enterarse sin preguntar. Cada canal se autoriza en `routes/channels.php`: la paciente no puede escuchar el canal de la doctora ni el de otro paciente.»

### Paso 3 — Reprogramar y cancelar
Pasos 3 y 4 del script.

- **Se ve:** `cita.reprogramacion_solicitada` y `cita.cancelada`.
- **Decir:** «No toqué cada función del servicio: el **observador** del modelo traduce cualquier cambio de estado en un evento con su tipo.»

### Paso 4 — Consumidor asíncrono (cola)
En la Laptop 3: `Get-Content storage\logs\eventos.log -Tail 4` (Linux: `tail -n 4`).

- **Se ve:** una línea JSON por evento.
- **Decir:** «Este consumidor va por una **cola**. La petición de la paciente no espera a que se escriba la bitácora; lo hace el worker en otro proceso.»

### Paso 5 — Balanceo
Señalar el «Procesado por backend1/backend2» de los avisos y la columna *atendido por* del script.

- **Decir:** «Las dos instancias publican al mismo Reverb. El cliente recibe el evento sin importar qué instancia atendió la petición.»

### Paso 6 — Resiliencia (el broker se cae)
1. Cerrar la ventana de **Reverb**. El panel cambia a **● Sin conexión en vivo** y el oyente a `unavailable`.
2. Repetir el paso 1 (con otra paciente o tras cancelar): responde **HTTP 201**, la cita se guarda.
3. Mostrar en `storage/logs/laravel-*.log`: `No se pudo difundir el evento de la cita por WebSocket` y que la bitácora **sí** registró el evento (son consumidores independientes).
4. Volver a abrir Reverb (`.\eventos.ps1` o `php artisan reverb:start`): el panel vuelve a **● En vivo** y se reconecta solo.

- **Decir:** «Que falle el sistema de notificaciones no detiene el negocio. Es **degradación controlada**, igual que con el servidor de archivos en la Unidad 3.»

### Paso 7 — Consistencia (opcional, con el código abierto)
Mostrar `AgendaObserver implements ShouldHandleEventsAfterCommit`.
- **Decir:** «Si la transacción falla (por ejemplo, el horario ya está ocupado), no se publica nada: nunca se avisa de una cita que no existe.» Lo cubre la prueba `test_no_event_is_published_when_the_transaction_rolls_back`.

---

## 6. Pruebas automatizadas

```bash
cd backend-api && php artisan test --filter=EventosCitaTest     # 10 pruebas
cd frontend-web && npx vitest run src/features/realtime          # 4 pruebas
```

| Prueba | Qué demuestra |
|---|---|
| `test_patient_request_publishes_a_solicitada_event` | El productor publica el evento correcto, con paciente e instancia. |
| `test_each_state_change_publishes_its_own_event_type` | Programar → reprogramar → cancelar producen tres eventos distintos, en orden. |
| `test_changes_that_are_not_relevant_…` | Editar la descripción o crear actividades personales no genera ruido. |
| `test_no_event_is_published_when_the_transaction_rolls_back` | Consistencia: sin COMMIT no hay evento. |
| `test_domain_event_has_two_independent_subscribers` | Publicar/suscribir: dos consumidores. |
| `test_the_log_subscriber_runs_through_the_queue` | La bitácora va por la cola `eventos`. |
| `test_broadcast_goes_to_the_doctor_and_to_the_patient_private_channels` | Canales y contenido del mensaje. |
| `test_a_websocket_failure_does_not_break_the_request` | Resiliencia: Reverb caído → HTTP 201 y advertencia en el log. |
| `test_private_channels_are_authorized_only_for_their_owner` | Seguridad de los canales privados. |
| `LiveAppointments.test.tsx` | La web refresca la agenda, muestra el aviso y cierra la conexión al salir. |

Resultado verificado (17-nov-2026), con PostgreSQL 18, dos instancias, Reverb y el worker:

```
[06:58:48] EVENTO cita.solicitada                 paciente=Ana Pérez  estado=pendiente  instancia=backend1
[06:58:48] EVENTO cita.programada                 paciente=Ana Pérez  estado=programada para 2026-11-20 09:00  instancia=backend1
[06:58:48] EVENTO cita.reprogramacion_solicitada  paciente=Ana Pérez  estado=pendiente  instancia=backend1
[06:58:48] EVENTO cita.cancelada                  paciente=Ana Pérez  estado=cancelada  instancia=backend1
Sin Reverb -> HTTP 201 en 0.080026s
```

![Panel de la doctora recibiendo el evento en vivo](img/eventos-panel-en-vivo.png)

---

## 7. Preguntas probables

**¿Qué diferencia hay entre un evento y una petición?**
Una petición pide algo y espera la respuesta («créame una cita»). Un evento informa algo que ya pasó («se solicitó una cita») y no espera nada de quien lo escucha.

**¿Por qué WebSocket y no consultar cada X segundos (polling)?**
Con polling, el aviso llega con hasta X segundos de retraso y hay peticiones aunque no cambie nada. El WebSocket deja una conexión abierta y el servidor empuja el mensaje en cuanto ocurre.

**¿Por qué un consumidor síncrono y otro por cola?**
La difusión tiene que ser inmediata, y si falla se ignora (el cliente se pone al día en su siguiente consulta). La bitácora no puede perderse pero no es urgente: la cola la desacopla y la reintenta (`$tries = 3`).

**¿Qué pasa si se cae el worker?**
Los eventos quedan en la tabla `jobs` y se procesan cuando vuelve. No se pierden.

**¿Qué pasa si se cae Reverb?**
Las citas se siguen guardando, el backend registra una advertencia y los clientes muestran «Sin conexión en vivo». Al volver Reverb, se reconectan solos.

**¿No se podría avisar de una cita que luego falló?**
No. El observador publica solo después del COMMIT (`ShouldHandleEventsAfterCommit`).

**¿Quién puede escuchar los eventos?**
Solo los usuarios autorizados por `routes/channels.php`: la doctora en `clinica.doctora` y cada paciente únicamente en su propio `paciente.{id}`. La autorización usa el mismo token Bearer de la sesión (`/api/broadcasting/auth`).

**¿Por qué Reverb y no Kafka o RabbitMQ?**
Reverb es el servidor de WebSockets oficial de Laravel, corre en PHP sin servicios extra y encaja con la decisión del equipo de no usar contenedores. Para el volumen de una clínica es suficiente. Con más volumen, Reverb puede escalar horizontalmente con Redis (`REVERB_SCALING_ENABLED`), y la cola podría pasar a Redis o RabbitMQ sin cambiar el productor ni los consumidores.

**¿Qué se podría agregar?**
Que la app móvil escuche el canal `paciente.{id}` para avisar a la paciente cuando la doctora programa su cita, y un consumidor que envíe recordatorios por correo.
