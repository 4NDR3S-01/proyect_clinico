<?php

namespace Tests\Feature;

use App\Models\AccionRegistrada;
use App\Models\Agenda;
use App\Models\Paciente;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgendaConcurrenciaTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_10_13_000001_add_no_overlap_constraint_to_agenda_table.php';

    private ?string $schema = null;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('AGENDA_TEST_PGSQL_URL');
        if (!$url) {
            $this->markTestSkipped('Define AGENDA_TEST_PGSQL_URL para ejecutar las pruebas en PostgreSQL.');
        }

        $this->schema = 'agenda_test_'.bin2hex(random_bytes(8));
        $connection = array_replace(config('database.connections.pgsql'), [
            'url' => $url,
            'search_path' => $this->schema,
        ]);
        config([
            'database.default' => 'agenda_test',
            'database.connections.agenda_test' => $connection,
            'database.connections.agenda_competidor' => $connection,
        ]);

        // Only this randomly named schema is created and removed; existing tables are never migrated.
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_25_012937_create_pacientes_table.php',
            '2026_09_25_163636_add_username_and_role_to_users_table.php',
            '2026_10_02_191138_create_agendas_table.php',
            '2026_10_03_030037_add_clinical_fields_to_agenda_table.php',
            '2026_10_03_191429_create_accion_registradas_table.php',
            basename(self::MIGRATION),
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->doctora = User::create([
            'name' => 'Doctora', 'email' => 'doctora@example.test',
            'password' => 'password-prueba', 'role' => 'doctora',
        ]);
        Sanctum::actingAs($this->doctora);
    }

    protected function tearDown(): void
    {
        AccionRegistrada::flushEventListeners();
        if ($this->schema !== null) {
            DB::purge('agenda_competidor');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            DB::purge('agenda_test');
        }

        parent::tearDown();
    }

    private function bloque(array $changes = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid(), 'tipo' => 'personal',
            'fecha' => '2026-10-20', 'hora_inicio' => '10:00', 'hora_fin' => '11:00',
        ], $changes);
    }

    public static function operacionesConHorario(): array
    {
        return array_map(fn ($method) => [$method], [
            'crearPersonal', 'crearCitaMedica', 'programarSolicitud', 'actualizar',
        ]);
    }

    #[DataProvider('operacionesConHorario')]
    public function test_race_after_availability_check_returns_409_without_partial_changes(string $method): void
    {
        $paciente = Paciente::create([
            'codigo_paciente' => '001', 'nombres' => 'Paciente', 'cedula' => '1234567890',
        ]);
        $data = [
            'fecha' => '2026-10-20', 'hora_inicio' => '10:30', 'hora_fin' => '11:30',
            'descripcion' => 'Nueva reserva', 'codigo_paciente' => $paciente->codigo_paciente,
        ];
        $registro = match ($method) {
            'programarSolicitud' => Agenda::create([
                'paciente_id' => $paciente->id, 'tipo' => 'cita_medica', 'estado' => 'pendiente',
            ]),
            'actualizar' => Agenda::create($this->bloque(['hora_inicio' => '12:00', 'hora_fin' => '13:00'])),
            default => null,
        };
        $original = $registro?->fresh()->getRawOriginal();
        $intercalada = false;
        DB::listen(function (QueryExecuted $query) use (&$intercalada) {
            if (
                !$intercalada && $query->connectionName === 'agenda_test'
                && str_starts_with($query->sql, 'select exists(')
                && str_contains($query->sql, '"agenda"')
            ) {
                // Another connection commits after this request saw a free slot, before its write.
                $intercalada = true;
                DB::connection('agenda_competidor')->table('agenda')->insert($this->bloque());
            }
        });

        $response = match ($method) {
            'crearPersonal' => $this->postJson('/api/agenda/personal', $data),
            'crearCitaMedica' => $this->postJson('/api/agenda/cita-medica', $data),
            'programarSolicitud' => $this->putJson('/api/agenda/solicitud/'.$registro->id.'/programar', $data),
            'actualizar' => $this->putJson('/api/agenda/'.$registro->id, $data),
        };

        $this->assertTrue($intercalada);
        $response->assertConflict()->assertExactJson(['message' => 'Ese horario ya se encuentra ocupado.']);
        $this->assertDatabaseCount('agenda', $registro ? 2 : 1);
        $this->assertDatabaseCount('acciones_registradas', 0);
        if ($registro) {
            $this->assertSame($original, $registro->fresh()->getRawOriginal());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function tiposQueBloquean(): array
    {
        return [['personal', null], ['cita_medica', 'programada'], ['cita_medica', 'completada']];
    }

    #[DataProvider('tiposQueBloquean')]
    public function test_database_rejects_overlaps_even_without_service(string $tipo, ?string $estado): void
    {
        DB::table('agenda')->insert($this->bloque(['tipo' => $tipo, 'estado' => $estado]));

        try {
            DB::table('agenda')->insert($this->bloque(['hora_inicio' => '10:59', 'hora_fin' => '12:00']));
            $this->fail('La base de datos debio impedir el cruce.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('agenda', 1);
    }

    public function test_adjacent_slots_other_dates_and_nonblocking_states_are_allowed(): void
    {
        DB::table('agenda')->insert($this->bloque());
        foreach ([
            ['hora_inicio' => '09:00', 'hora_fin' => '10:00'],
            ['hora_inicio' => '11:00', 'hora_fin' => '12:00'],
            ['fecha' => '2026-10-21'],
            ['tipo' => 'cita_medica', 'estado' => 'cancelada'],
            ['tipo' => 'cita_medica', 'estado' => 'pendiente', 'fecha' => null, 'hora_inicio' => null, 'hora_fin' => null],
        ] as $changes) {
            DB::table('agenda')->insert($this->bloque($changes));
        }
        $this->assertDatabaseCount('agenda', 6);
    }

    public function test_migration_rejects_existing_overlaps_without_changing_records(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();
        DB::table('agenda')->insert([$this->bloque(), $this->bloque()]);
        $before = DB::table('agenda')->orderBy('id')->get()->toJson();

        try {
            $migration->up();
            $this->fail('La migracion debio rechazar los cruces existentes.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
        $this->assertSame($before, DB::table('agenda')->orderBy('id')->get()->toJson());
    }

    public static function erroresAjenosAlHorario(): array
    {
        return [['23505', 'agenda_sin_solapamientos'], ['23P01', 'otra_restriccion']];
    }

    #[DataProvider('erroresAjenosAlHorario')]
    public function test_unrelated_database_errors_propagate_and_roll_back(string $state, string $constraint): void
    {
        $previous = new PDOException('Fallo simulado');
        $previous->errorInfo = [$state, 7, 'constraint "'.$constraint.'"'];
        $error = new QueryException('agenda_test', 'insert into acciones_registradas', [], $previous);
        AccionRegistrada::creating(function () use ($error) {
            throw $error;
        });

        try {
            app(AgendaService::class)->crearPersonal([
                'fecha' => '2026-10-20', 'hora_inicio' => '10:00', 'hora_fin' => '11:00',
                'descripcion' => 'Reserva',
            ], $this->doctora);
            $this->fail('El error ajeno al horario debio propagarse.');
        } catch (QueryException $exception) {
            $this->assertSame($error, $exception);
        }
        $this->assertDatabaseCount('agenda', 0);
        $this->assertDatabaseCount('acciones_registradas', 0);
    }

    public static function resultadosPrimeraTransaccion(): array
    {
        return [['commit'], ['rollBack']];
    }

    #[DataProvider('resultadosPrimeraTransaccion')]
    public function test_concurrent_insert_waits_for_commit_or_rollback(string $finish): void
    {
        if (!extension_loaded('pgsql')) {
            $this->markTestSkipped('La prueba asincrona requiere la extension pgsql.');
        }

        $config = DB::connection()->getConfig();
        $parameters = ['host' => $config['host'], 'port' => $config['port'],
            'dbname' => $config['database'], 'user' => $config['username'],
            'password' => $config['password'] ?? '', 'sslmode' => $config['sslmode']];
        $connectionString = implode(' ', array_map(
            fn ($key, $value) => $key."='".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'",
            array_keys($parameters), $parameters
        ));
        $competidor = pg_connect($connectionString.' connect_timeout=5', PGSQL_CONNECT_FORCE_NEW);

        try {
            pg_query($competidor, 'SET search_path TO "'.$this->schema.'"');
            pg_query($competidor, "SET statement_timeout = '5s'");
            pg_query($competidor, 'BEGIN');
            DB::beginTransaction();
            $this->assertSame(0, DB::table('agenda')->count());
            $this->assertSame('0', pg_fetch_result(pg_query($competidor, 'SELECT count(*) FROM agenda'), 0, 0));

            DB::table('agenda')->insert($this->bloque());
            pg_send_query_params($competidor,
                "INSERT INTO agenda (id, tipo, fecha, hora_inicio, hora_fin) VALUES ($1, 'personal', '2026-10-20', '10:30', '11:30')",
                [(string) Str::uuid()]
            );

            $waiting = false;
            $deadline = microtime(true) + 3;
            do {
                $waiting = (bool) DB::scalar('SELECT pg_backend_pid() = ANY(pg_blocking_pids(?))', [pg_get_pid($competidor)]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'La segunda reserva debe esperar a la primera transaccion.');

            DB::connection()->$finish();
            $result = pg_get_result($competidor);
            if ($finish === 'commit') {
                $this->assertSame('23P01', pg_result_error_field($result, PGSQL_DIAG_SQLSTATE));
                pg_query($competidor, 'ROLLBACK');
            } else {
                $this->assertSame(PGSQL_COMMAND_OK, pg_result_status($result));
                pg_query($competidor, 'COMMIT');
            }
            $this->assertDatabaseCount('agenda', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            pg_close($competidor);
        }
    }
}
