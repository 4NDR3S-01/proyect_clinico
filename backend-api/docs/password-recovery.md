# Recuperación de contraseña y Brevo SMTP

Configurar estas variables en el entorno del servidor (no subir claves al repositorio):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.example
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=465
MAIL_USERNAME="TU_LOGIN_SMTP_DE_BREVO"
MAIL_PASSWORD="TU_CLAVE_SMTP_DE_BREVO"
MAIL_FROM_ADDRESS="remitente@tu-dominio.example"
MAIL_FROM_NAME="Consultorio"
MAIL_TIMEOUT=15
```

Usar el login SMTP y una **clave SMTP**, no una clave API ni la contraseña de la cuenta.
El puerto 465 usa TLS desde la conexión inicial. El servidor debe permitir la salida
a ese puerto. Eliminar MAIL_URL si existe y contradice estos valores.
Verificar el remitente y autenticar el dominio en Brevo; revisar que el servicio de
correo transaccional esté activo y que la IP del servidor esté autorizada si se
usan restricciones de IP.

Referencias oficiales:
- https://help.brevo.com/hc/en-us/articles/7924908994450-Send-transactional-emails-using-Brevo-SMTP
- https://help.brevo.com/hc/en-us/articles/7959631848850-Create-and-manage-your-SMTP-keys

Aplicar las dos migraciones de recuperación, en este orden, desde backend-api:

```sh
php artisan migrate --path=database/migrations/2026_10_13_230949_create_password_reset_codes_table.php --force
php artisan migrate --path=database/migrations/2026_10_14_000002_add_attempts_to_password_reset_codes_table.php --force
php artisan config:cache
```

Usar PostgreSQL en producción para los bloqueos de filas. Mantener un almacén de
caché compartido y persistente (database o Redis) entre todos los procesos/servidores.
Los proxies deben configurarse para que Laravel obtenga la IP real del cliente.

## Contrato de la API

- POST /api/auth/forgot-password: JSON con email. Respuesta 200 genérica para
  cuentas activas, inexistentes o desactivadas. No garantiza entrega a la bandeja.
- Un reenvío por minuto y cinco por hora por correo; diez solicitudes por minuto
  por IP. Al limitar se devuelve 429 con Retry-After.
- POST /api/auth/reset-password: email, code (cadena de seis dígitos), password
  (mínimo 8 caracteres, máximo 72 bytes), password_confirmation. Respuesta 200 al cambiarla;
  422 para datos/código inválidos; 429 al superar límites.
- El código vence a los cinco minutos y queda bloqueado tras cinco fallos,
  incluso si cambia la IP o vence el límite temporal de solicitudes.
- Cada reenvío exitoso reemplaza el código anterior. El cambio revoca los tokens
  Sanctum existentes y rota remember_token. La aplicación usa autenticación por
  tokens; esto no añade una política para sesiones web de otros sistemas.
- Un fallo SMTP revierte el nuevo código y conserva el anterior. El cliente recibe
  la respuesta genérica para no revelar cuentas; monitorizar el evento
  password_reset_mail_failed en logs y las entregas/rebotes en Brevo.
- En producción, los transportes simulados o failover no se aceptan (503).
  El envío es síncrono, con timeout de 15 segundos, dentro de la transacción:
  no necesita worker. SMTP y base de datos no comparten una transacción; si SMTP
  acepta un mensaje y la confirmación de base falla, ese código no será válido.

## Validación antes de abrir el servicio

```sh
php artisan test --compact --filter=PasswordRecoveryTest
```

Las pruebas usan SQLite en memoria y correo array, sin enviar mensajes reales.
No prueban el bloqueo de filas de PostgreSQL ni la entrega real de Brevo.
Tras configurar el servidor, solicitar un código para una cuenta controlada,
confirmar recepción y entrega en Brevo, restablecer contraseña y comprobar
que el código y los tokens anteriores ya no funcionan. No registrar códigos ni claves.
