# Pruebas

La suite habitual usa SQLite en memoria:

```powershell
php artisan test --compact
```

Las pruebas de exclusión y concurrencia de agenda necesitan PostgreSQL y las
extensiones PHP `pdo_pgsql` y `pgsql`. Usa una base de pruebas con permiso para
crear esquemas. Cada caso crea y elimina su propio esquema `agenda_test_*`;
no ejecuta migraciones sobre las tablas existentes.

```powershell
$env:AGENDA_TEST_PGSQL_URL = 'postgresql://usuario:password@127.0.0.1:5432/clinico_test'
php artisan test --compact
```

Sin esta variable, `AgendaConcurrenciaTest` se omite explícitamente. La prueba de
concurrencia usa dos conexiones reales y comprueba tanto la confirmación como
el rollback de la primera reserva.

# Activar la protección de agenda

En la base PostgreSQL de la aplicación, aplica la migración nueva:

```powershell
php artisan migrate --path=database/migrations/2026_10_13_000001_add_no_overlap_constraint_to_agenda_table.php
```

La restricción `agenda_sin_solapamientos` rechaza los cruces entre actividades
personales y citas programadas o completadas. Permite horarios contiguos y
excluye citas canceladas y solicitudes pendientes. No requiere extensiones
adicionales de PostgreSQL.

Si ya existen cruces, PostgreSQL rechaza la migración sin modificar las
reservas. Deben revisarse esos registros antes de volver a aplicarla. La
protección de concurrencia solo queda activa al aplicar esta migración.
