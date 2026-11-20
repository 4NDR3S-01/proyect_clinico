# Práctica 2.4: seguridad de la cita médica

Práctica individual de William Cabrera (IS-803, 2026-2). Se protegen la consulta y la cancelación de una cita médica con autenticación (Sanctum), autorización por rol y propietario (policy) y una configuración de secretos revisada.

## Endpoints protegidos

| Endpoint | Método | Tipo | Público | Paciente | Asistente | Doctora |
|---|---|---|---|---|---|---|
| `/api/auth/login` | POST | Obtiene el token | Sí | Sí | Sí | Sí |
| `/api/seguridad/citas-medicas/{id}` | GET | Consulta | No (401) | Solo sus citas (403 si es ajena) | Sí | Sí |
| `/api/seguridad/citas-medicas/{id}/cancelar` | PUT | Modifica datos | No (401) | Solo sus citas (403 si es ajena) | No (403) | Sí |

Si la cita no existe o no es una cita médica, la respuesta es 404. Una cita cancelada, completada o que ya empezó devuelve 409 (reglas de `AgendaService`).

## Cómo funciona

1. `auth:sanctum` exige `Authorization: Bearer <token>`. Si falta el token, es inválido, fue revocado por logout o venció, responde **401**.
2. `role:doctora,asistente,paciente` rechaza con **403** a cualquier otro rol.
3. `CitaMedicaPolicy` decide sobre la cita concreta:
   - `ver`: doctora y asistente, cualquier cita; paciente, solo si `users.paciente_id` es el `paciente_id` de la cita.
   - `cancelar`: doctora, cualquier cita; paciente, solo la suya; asistente, nunca.
4. La cancelación usa los métodos ya existentes de `AgendaService` (`cancelarCita` para la doctora y `cancelarMiCita` para el paciente).

| Archivo | Cambio |
|---|---|
| `app/Policies/CitaMedicaPolicy.php` | Reglas por rol y propietario (nuevo) |
| `app/Http/Controllers/Api/Seguridad/CitaMedicaProtegidaController.php` | Busca la cita, autoriza con `Gate::authorize` y responde (nuevo) |
| `routes/api.php` | Grupo `seguridad/citas-medicas` con `auth:sanctum` y `role` |
| `app/Providers/AppServiceProvider.php` | `Gate::policy(Agenda::class, CitaMedicaPolicy::class)` |
| `bootstrap/app.php` | La API responde siempre en JSON y no redirige a un login web |
| `config/sanctum.php` | Los tokens vencen (`SANCTUM_EXPIRATION`, 480 min por defecto) |
| `.env.example` | `APP_DEBUG=false` y `SANCTUM_EXPIRATION=480` |
| `.gitignore` | Excluye `.env.*` salvo `.env.example` |
| `despliegue/instalar.ps1` | Avisa si el servidor queda con `APP_DEBUG=true` |
| `tests/Feature/SeguridadCitaMedicaTest.php` | 19 pruebas P1 a P4 con tokens reales (nuevo) |

## Problemas encontrados y corregidos

- **Sin `Accept: application/json`, una petición sin token devolvía 500** (`Route [login] not defined`), porque Laravel intentaba redirigir a un login web que la API no tiene. Ahora es 401 en JSON.
- **Los tokens no vencían** (`expiration => null`). Un token robado servía para siempre. Ahora vence a las 8 horas.
- **`.env.example` traía `APP_DEBUG=true`** y el instalador lo copia como `.env`. Con eso, un 403 o un 500 en el servidor devolvía la ruta de los archivos y la traza completa. Ahora el ejemplo trae `false` y el instalador avisa.
- **`.gitignore` solo excluía `.env`, `.env.backup` y `.env.production`.** Un archivo como `.env.respaldo` con credenciales podía subirse por error. Ahora se excluye `.env.*`.

## Variables de entorno (solo nombres)

| Variable | Uso |
|---|---|
| `APP_KEY` | Clave de cifrado de Laravel (`php artisan key:generate`) |
| `APP_DEBUG` | `false` en los servidores |
| `DB_USERNAME`, `DB_PASSWORD` | Usuario de PostgreSQL de la aplicación (Laptop 4) |
| `FTP_USERNAME`, `FTP_PASSWORD` | Servidor de archivos (Laptop 5) |
| `BREVO_API_KEY` | Envío de correos |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | WebSockets de Reverb |
| `DOCTORA_PASSWORD` | Contraseña inicial de la doctora para el seeder |
| `SANCTUM_EXPIRATION` | Minutos de vida de los tokens |
| `TRUSTED_PROXIES` | IP del balanceador, para confiar en `X-Forwarded-*` |

En el repositorio solo está `.env.example`, con las credenciales vacías. Las contraseñas no se guardan en texto plano (el modelo `User` usa el cast `hashed` y oculta `password`). Sanctum guarda en la base solo el hash SHA-256 del token, y el token completo se entrega una sola vez en el login.

## HTTPS / TLS

- **Demo local** (`127.0.0.1:8000`, `php artisan serve`): HTTP sin TLS. El tráfico **no** va cifrado.
- **Red del proyecto por switch**: NGINX (Laptop 1) escucha en el puerto 80. Tampoco va cifrado.
- **Red por Tailscale**: los paquetes viajan dentro del túnel WireGuard de Tailscale, cifrado entre los nodos, aunque la aplicación siga usando HTTP.
- **Acceso público (Tailscale Funnel)**: el cliente se conecta por HTTPS con el certificado de Tailscale, y el TLS termina en la Laptop 1.

Para cifrar también en el switch: en NGINX, `listen 443 ssl` con un certificado (`tailscale cert` o una CA propia del laboratorio), redirección 301 de 80 a 443 y la cabecera `Strict-Transport-Security`. Laravel ya confía en el balanceador por `TRUSTED_PROXIES`, así que reconoce `X-Forwarded-Proto: https`.

## Pruebas

```bash
cd backend-api
php artisan test --filter=SeguridadCitaMedica
```

Para probarlo a mano, primero hay que hacer `POST /api/auth/login` con `{"username": "...", "password": "..."}` y enviar el `access_token` en `Authorization: Bearer <token>`. Hay que usar usuarios de prueba y no mostrar el token en las capturas.

| Caso | Condición | Esperado | Obtenido |
|---|---|---|---|
| P1 | Doctora consulta; paciente consulta y cancela su cita | 2xx | 200 |
| P2 | Sin token, con token inválido, vencido o revocado | 401 | 401 |
| P3 | Paciente con una cita ajena; asistente que cancela | 403 | 403 |
| P4 | `.env` excluido, `.env.example` sin secretos, tokens con vencimiento | Sin secretos | Correcto |
