<?php

namespace Tests\Feature;

use App\Models\Agenda;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Práctica 2.4 (William Cabrera): autenticación, autorización y gestión de
 * credenciales en la consulta y cancelación de una cita médica.
 *
 * Todas las peticiones usan un token real de Sanctum (Authorization: Bearer),
 * igual que la web y la app móvil. Los datos son ficticios.
 */
class SeguridadCitaMedicaTest extends TestCase
{
    private const URL = '/api/seguridad/citas-medicas';

    private User $doctora;

    private User $asistente;

    private User $pacienteDueno;

    private User $pacienteAjeno;

    private Agenda $cita;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_19_182046_create_personal_access_tokens_table.php',
            '2026_09_25_012937_create_pacientes_table.php',
            '2026_09_25_163636_add_username_and_role_to_users_table.php',
            '2026_09_25_164339_add_paciente_id_to_users_table.php',
            '2026_10_02_191138_create_agendas_table.php',
            '2026_10_03_030037_add_clinical_fields_to_agenda_table.php',
            '2026_10_03_191429_create_accion_registradas_table.php',
            '2026_11_10_202239_add_nombre_reserva_to_agenda_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $pacienteA = Paciente::create([
            'codigo_paciente' => '007',
            'nombres' => 'Paciente Ficticio A',
            'cedula' => '0000000007',
        ]);

        $pacienteB = Paciente::create([
            'codigo_paciente' => '008',
            'nombres' => 'Paciente Ficticio B',
            'cedula' => '0000000008',
        ]);

        $this->doctora = $this->usuario('doctora');
        $this->asistente = $this->usuario('asistente');
        $this->pacienteDueno = $this->usuario('paciente', $pacienteA->id);
        $this->pacienteAjeno = $this->usuario('paciente', $pacienteB->id);

        $this->cita = Agenda::create([
            'paciente_id' => $pacienteA->id,
            'fecha' => now()->addDays(3)->format('Y-m-d'),
            'hora_inicio' => '09:00',
            'hora_fin' => '09:30',
            'tipo' => 'cita_medica',
            'descripcion' => 'Limpieza dental',
            'estado' => 'programada',
        ]);
    }

    // P1: autenticación y permisos válidos (2xx)

    public function test_p1_doctora_consulta_cualquier_cita(): void
    {
        $this->conToken($this->doctora)
            ->getJson($this->urlCita())
            ->assertOk()
            ->assertJsonPath('data.id', $this->cita->id);
    }

    public function test_p1_asistente_consulta_cualquier_cita(): void
    {
        $this->conToken($this->asistente)
            ->getJson($this->urlCita())
            ->assertOk();
    }

    public function test_p1_paciente_consulta_su_propia_cita(): void
    {
        $this->conToken($this->pacienteDueno)
            ->getJson($this->urlCita())
            ->assertOk()
            ->assertJsonPath('data.paciente_id', $this->cita->paciente_id);
    }

    public function test_p1_paciente_cancela_su_propia_cita(): void
    {
        $this->conToken($this->pacienteDueno)
            ->putJson($this->urlCita().'/cancelar')
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada');
    }

    public function test_p1_doctora_cancela_cualquier_cita(): void
    {
        $this->conToken($this->doctora)
            ->putJson($this->urlCita().'/cancelar')
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada');
    }

    // P2: sin autenticación (401)

    public function test_p2_sin_token_devuelve_401(): void
    {
        $this->getJson($this->urlCita())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->putJson($this->urlCita().'/cancelar')->assertUnauthorized();
    }

    public function test_p2_sin_token_y_sin_cabecera_accept_tambien_es_401_json(): void
    {
        // Un cliente que no envía Accept: application/json no debe recibir un 500
        // por una redirección a un login web que la API no tiene.
        $this->get($this->urlCita())
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_p2_token_invalido_devuelve_401(): void
    {
        $this->withToken('1|token-inventado-que-no-existe')
            ->getJson($this->urlCita())
            ->assertUnauthorized();
    }

    public function test_p2_token_vencido_devuelve_401(): void
    {
        $token = $this->doctora->createToken('auth_token')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->withToken($token)
            ->getJson($this->urlCita())
            ->assertUnauthorized();
    }

    public function test_p2_token_revocado_por_logout_devuelve_401(): void
    {
        $token = $this->doctora->createToken('auth_token')->plainTextToken;
        $this->doctora->tokens()->delete();

        $this->withToken($token)
            ->getJson($this->urlCita())
            ->assertUnauthorized();
    }

    // P3: autenticado sin permisos (403)

    public function test_p3_paciente_no_consulta_cita_ajena(): void
    {
        $this->conToken($this->pacienteAjeno)
            ->getJson($this->urlCita())
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes consultar una cita médica que no es tuya.');
    }

    public function test_p3_paciente_no_cancela_cita_ajena(): void
    {
        $this->conToken($this->pacienteAjeno)
            ->putJson($this->urlCita().'/cancelar')
            ->assertForbidden();

        $this->assertSame('programada', $this->cita->fresh()->estado);
    }

    public function test_p3_asistente_no_cancela_citas(): void
    {
        $this->conToken($this->asistente)
            ->putJson($this->urlCita().'/cancelar')
            ->assertForbidden()
            ->assertJsonPath('message', 'El rol asistente solo puede consultar citas médicas.');

        $this->assertSame('programada', $this->cita->fresh()->estado);
    }

    public function test_p3_rol_desconocido_es_rechazado(): void
    {
        $this->conToken($this->usuario('invitado'))
            ->getJson($this->urlCita())
            ->assertForbidden();
    }

    // P4: configuración y secretos

    public function test_p4_los_tokens_tienen_vencimiento(): void
    {
        $this->assertGreaterThan(0, (int) config('sanctum.expiration'));
    }

    public function test_p4_las_respuestas_no_exponen_password_ni_token(): void
    {
        $token = $this->doctora->createToken('auth_token')->plainTextToken;

        $cuerpo = $this->withToken($token)
            ->getJson($this->urlCita())
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(explode('|', $token)[1], $cuerpo);
        $this->assertStringNotContainsStringIgnoringCase('password', $cuerpo);
    }

    public function test_p4_un_403_no_escribe_el_token_en_los_logs(): void
    {
        $token = $this->pacienteAjeno->createToken('auth_token')->plainTextToken;

        Log::spy();

        $this->withToken($token)->getJson($this->urlCita())->assertForbidden();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_p4_el_env_esta_excluido_del_repositorio(): void
    {
        $raiz = dirname(base_path());

        if (! is_dir($raiz.'/.git')) {
            $this->markTestSkipped('Sin repositorio git disponible.');
        }

        $versionados = shell_exec('git -C '.escapeshellarg($raiz).' ls-files backend-api');
        $archivos = preg_split('/\R/', trim((string) $versionados));

        $this->assertContains('backend-api/.env.example', $archivos);
        $this->assertNotContains('backend-api/.env', $archivos);
        $this->assertSame([], preg_grep('#^backend-api/\.env\.(?!example$)#', $archivos));

        exec('git -C '.escapeshellarg($raiz).' check-ignore -q backend-api/.env', $salida, $codigo);
        $this->assertSame(0, $codigo, 'backend-api/.env debe estar en .gitignore');
    }

    public function test_p4_env_example_no_trae_credenciales_reales(): void
    {
        $ejemplo = file_get_contents(base_path('.env.example'));

        foreach (['APP_KEY', 'DB_PASSWORD', 'FTP_PASSWORD', 'BREVO_API_KEY'] as $variable) {
            if (preg_match('/^'.$variable.'=(.*)$/m', $ejemplo, $m)) {
                $this->assertSame('', trim($m[1]), "$variable debe ir vacío en .env.example");
            }
        }

        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $ejemplo);
    }

    private function usuario(string $rol, ?string $pacienteId = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "Usuario {$rol} {$n}",
            'email' => "{$rol}{$n}@example.test",
            'username' => "{$rol}{$n}",
            'password' => 'clave-ficticia-de-prueba',
            'role' => $rol,
            'paciente_id' => $pacienteId,
        ]);
    }

    private function conToken(User $user): static
    {
        return $this->withToken($user->createToken('auth_token')->plainTextToken);
    }

    private function urlCita(): string
    {
        return self::URL.'/'.$this->cita->id;
    }
}
