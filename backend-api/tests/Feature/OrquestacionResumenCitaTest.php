<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PeticionSaliente;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Práctica 3.3 (William Cabrera): orquestador GET /api/resumen-cita/{id}.
 * Los servicios de citas y pacientes se simulan con Http::fake; la prueba
 * con servicios reales está en docs/practica-orquestacion-resumen-cita.md.
 */
class OrquestacionResumenCitaTest extends TestCase
{
    private const CITA = '01a1faae-0cc2-7031-9681-7fabb8bd7035';

    private const PACIENTE = '01a1faae-065e-704a-9fed-03208a6f212d';

    private const CITAS = 'http://citas.test/api';

    private const PACIENTES = 'http://pacientes.test/api';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_19_182046_create_personal_access_tokens_table.php',
            '2026_09_25_163636_add_username_and_role_to_users_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        config([
            'orquestacion.citas_url' => self::CITAS,
            'orquestacion.pacientes_url' => self::PACIENTES,
        ]);
    }

    // Prueba satisfactoria

    public function test_compone_la_cita_y_el_paciente_con_el_contrato_de_la_web(): void
    {
        $this->servicios();

        $this->conToken('doctora')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertOk()
            ->assertJsonPath('data', [
                'cita' => [
                    'id' => self::CITA,
                    'fecha' => '2026-11-27',
                    'hora_inicio' => '09:00',
                    'hora_fin' => '09:30',
                    'estado' => 'programada',
                    'descripcion' => 'Limpieza dental',
                ],
                'paciente' => [
                    'codigo_paciente' => '007',
                    'nombres' => 'Paciente Ficticio A',
                ],
            ]);
    }

    public function test_consulta_primero_citas_y_luego_pacientes_con_el_id_que_dio_citas(): void
    {
        $this->servicios();

        $this->conToken('doctora')->getJson('/api/resumen-cita/'.self::CITA)->assertOk();

        $urls = collect(Http::recorded())->map(fn ($par) => $par[0]->url())->all();
        $this->assertSame([
            self::CITAS.'/agenda/'.self::CITA,
            self::PACIENTES.'/pacientes/'.self::PACIENTE,
        ], $urls);
    }

    public function test_reenvia_el_token_y_el_correlation_id_a_los_dos_servicios(): void
    {
        $this->servicios();
        $token = User::create($this->datos('doctora'))->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Correlation-ID', 'web-resumen-0001')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'web-resumen-0001')
            ->assertJsonPath('correlation_id', 'web-resumen-0001');

        Http::assertSentCount(2);
        Http::assertSent(fn (PeticionSaliente $p) => $p->hasHeader('Authorization', 'Bearer '.$token)
            && $p->hasHeader('X-Correlation-ID', 'web-resumen-0001'));
    }

    public function test_no_expone_datos_del_paciente_que_la_web_no_usa(): void
    {
        $this->servicios();

        $cuerpo = $this->conToken('doctora')->getJson('/api/resumen-cita/'.self::CITA)->getContent();

        foreach (['cedula', '0000000007', 'telefono', 'direccion'] as $dato) {
            $this->assertStringNotContainsString($dato, $cuerpo);
        }
    }

    // Fallos y degradación

    public function test_si_el_servicio_de_pacientes_no_responde_devuelve_503(): void
    {
        $this->servicios(pacientes: fn () => throw new ConnectionException('Connection refused'));

        $this->conToken('doctora')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '10')
            ->assertJsonPath('servicio', 'pacientes')
            ->assertJsonPath('message', 'El servicio de pacientes no está disponible.')
            ->assertJsonMissingPath('data');
    }

    public function test_si_el_servicio_de_citas_no_responde_no_llama_a_pacientes(): void
    {
        $this->servicios(citas: fn () => throw new ConnectionException('timeout'));

        $this->conToken('doctora')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertStatus(503)
            ->assertJsonPath('servicio', 'citas');

        Http::assertNotSent(fn (PeticionSaliente $p) => str_contains($p->url(), 'pacientes.test'));
    }

    public function test_un_error_500_de_un_servicio_se_informa_como_503(): void
    {
        $this->servicios(pacientes: Http::response(['message' => 'Server Error'], 500));

        $this->conToken('doctora')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertStatus(503)
            ->assertJsonPath('servicio', 'pacientes');
    }

    public function test_una_respuesta_que_no_cumple_el_contrato_devuelve_502(): void
    {
        // El servicio de pacientes devuelve otro paciente.
        $this->servicios(pacientes: Http::response(['data' => $this->paciente(['id' => 'otro-id'])]));

        $this->conToken('doctora')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertStatus(502)
            ->assertJsonPath('message', 'El servicio de pacientes devolvió una respuesta inválida.');
    }

    public function test_cita_inexistente_devuelve_404(): void
    {
        $this->servicios(citas: Http::response(['message' => 'Registro de agenda no encontrado.'], 404));

        $this->conToken('doctora')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertNotFound()
            ->assertJsonPath('message', 'La cita no existe.');
    }

    public function test_un_registro_personal_de_la_agenda_devuelve_404(): void
    {
        $this->servicios(citas: Http::response(['data' => $this->cita(['tipo' => 'personal', 'paciente_id' => null])]));

        $this->conToken('doctora')->getJson('/api/resumen-cita/'.self::CITA)->assertNotFound();
    }

    public function test_cita_sin_paciente_registrado_devuelve_data_null(): void
    {
        $this->servicios(citas: Http::response(['data' => $this->cita(['paciente_id' => null, 'paciente' => null])]));

        $this->conToken('doctora')
            ->getJson('/api/resumen-cita/'.self::CITA)
            ->assertOk()
            ->assertJsonPath('data', null);

        Http::assertSentCount(1);
    }

    public function test_si_un_servicio_rechaza_el_token_se_devuelve_el_mismo_codigo(): void
    {
        $this->servicios(pacientes: Http::response(['message' => 'Unauthenticated.'], 401));

        $this->conToken('doctora')->getJson('/api/resumen-cita/'.self::CITA)->assertUnauthorized();
    }

    // Acceso

    public function test_sin_token_es_401_y_otro_rol_es_403(): void
    {
        $this->servicios();

        $this->getJson('/api/resumen-cita/'.self::CITA)->assertUnauthorized();

        Sanctum::actingAs(User::create($this->datos('paciente')));
        $this->getJson('/api/resumen-cita/'.self::CITA)->assertForbidden();

        Http::assertNothingSent();
    }

    private function servicios(mixed $citas = null, mixed $pacientes = null): void
    {
        Http::fake([
            'citas.test/*' => $citas ?? Http::response(['data' => $this->cita()]),
            'pacientes.test/*' => $pacientes ?? Http::response(['data' => $this->paciente()]),
        ]);
    }

    private function cita(array $cambios = []): array
    {
        return array_replace([
            'id' => self::CITA,
            'paciente_id' => self::PACIENTE,
            'fecha' => '2026-11-27',
            'hora_inicio' => '09:00:00',
            'hora_fin' => '09:30:00',
            'tipo' => 'cita_medica',
            'descripcion' => 'Limpieza dental',
            'estado' => 'programada',
        ], $cambios);
    }

    private function paciente(array $cambios = []): array
    {
        return array_replace([
            'id' => self::PACIENTE,
            'codigo_paciente' => '007',
            'nombres' => 'Paciente Ficticio A',
            'cedula' => '0000000007',
            'telefono' => '0990000007',
            'direccion' => null,
        ], $cambios);
    }

    private function datos(string $rol): array
    {
        static $n = 0;
        $n++;

        return [
            'name' => "Usuario {$rol} {$n}",
            'email' => "{$rol}{$n}@example.test",
            'username' => "{$rol}{$n}",
            'password' => 'clave-ficticia',
            'role' => $rol,
        ];
    }

    private function conToken(string $rol): static
    {
        return $this->withToken(User::create($this->datos($rol))->createToken('auth_token')->plainTextToken);
    }
}
