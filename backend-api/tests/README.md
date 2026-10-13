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

## Una solicitud pendiente por paciente

Aplica también la migración del índice único parcial:

```powershell
php artisan migrate --path=database/migrations/2026_10_14_000001_add_unique_pending_request_to_agenda_table.php
```

El índice `agenda_paciente_pendiente_unico` permite una sola cita médica
pendiente por paciente. Al programarla o cancelarla, se permite otra solicitud.
Si dos peticiones compiten, una se guarda y la otra recibe HTTP 409.

Si ya existen solicitudes pendientes duplicadas, la migración falla sin
eliminarlas ni cambiarlas. Puedes detectarlas antes de aplicar la migración:

```sql
SELECT paciente_id, COUNT(*) AS cantidad
FROM agenda
WHERE tipo = 'cita_medica' AND estado = 'pendiente'
  AND paciente_id IS NOT NULL
GROUP BY paciente_id
HAVING COUNT(*) > 1;
```

Las pruebas de `AgendaConcurrenciaTest` verifican el conflicto HTTP, la
restricción con dos conexiones, commit y rollback, cambios de estado y rechazo
de duplicados preexistentes. La protección requiere aplicar la migración;
el cambio del servicio por sí solo no impide solicitudes simultáneas.
