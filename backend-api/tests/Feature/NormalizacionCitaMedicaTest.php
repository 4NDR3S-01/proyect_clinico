<?php

namespace Tests\Feature;

use App\Models\Agenda;
use App\Models\Paciente;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\NormalizadorCitaMedicaTest;

/**
 * Integración HTTP de la Práctica 2.3 (William Cabrera): los dos orígenes se
 * traducen al contrato común y se registran con el AgendaService existente.
 */
class NormalizacionCitaMedicaTest extends TestCase
{
    private const URL = '/api/normalizacion/cita-medica';

    private const CONTRATO = [
        'codigo_paciente' => '007',
        'fecha' => '2026-11-20',
        'hora_inicio' => '09:00',
        'hora_fin' => '09:30',
        'descripcion' => 'Limpieza dental',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_25_012937_create_pacientes_table.php',
            '2026_09_25_163636_add_username_and_role_to_users_table.php',
            '2026_10_02_191138_create_agendas_table.php',
            '2026_10_03_030037_add_clinical_fields_to_agenda_table.php',
            '2026_10_03_191429_create_accion_registradas_table.php',
            '2026_11_10_202239_add_nombre_reserva_to_agenda_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        Paciente::create([
            'codigo_paciente' => '007',
            'nombres' => 'Paciente Ficticio',
            'cedula' => '0000000007',
        ]);

        Sanctum::actingAs(User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora@example.test',
            'password' => 'password-prueba',
            'role' => 'doctora',
        ]));
    }

    public function test_p1_origen_web_se_normaliza_al_contrato_comun(): void
    {
        $this->postJson(self::URL.'/web', NormalizadorCitaMedicaTest::entradaWeb())
            ->assertOk()
            ->assertExactJson([
                'message' => 'Entrada normalizada al contrato común.',
                'origen' => 'web',
                'data' => self::CONTRATO,
            ]);
    }

    public function test_p2_origen_movil_equivalente_produce_el_mismo_contrato(): void
    {
        $web = $this->postJson(self::URL.'/web', NormalizadorCitaMedicaTest::entradaWeb())->json('data');
        $movil = $this->postJson(self::URL.'/movil', NormalizadorCitaMedicaTest::entradaMovil())
            ->assertOk()
            ->json('data');

        $this->assertSame($web, $movil);
    }

    public function test_p3_campo_faltante_devuelve_422_con_errores_por_campo(): void
    {
        $entrada = NormalizadorCitaMedicaTest::entradaMovil();
        unset($entrada['patient']);
        $entrada['appointment']['startTime'] = '25:00 PM';

        $this->postJson(self::URL.'/movil', $entrada)
            ->assertStatus(422)
            ->assertJsonPath('origen', 'movil')
            ->assertJsonValidationErrors(['patient', 'appointment.startTime'], 'errors');
    }

    public function test_registro_desde_movil_guarda_la_cita_con_el_contrato_comun(): void
    {
        $this->postJson(self::URL.'/movil/registrar', NormalizadorCitaMedicaTest::entradaMovil())
            ->assertCreated()
            ->assertJsonPath('contrato', self::CONTRATO)
            ->assertJsonPath('data.tipo', 'cita_medica');

        $cita = Agenda::sole();
        $this->assertSame(Paciente::where('codigo_paciente', '007')->value('id'), $cita->paciente_id);
        $this->assertSame('09:00', substr((string) $cita->hora_inicio, 0, 5));
        $this->assertSame('09:30', substr((string) $cita->hora_fin, 0, 5));
    }

    public function test_misma_cita_desde_el_otro_origen_choca_con_el_horario_ocupado(): void
    {
        $this->postJson(self::URL.'/web/registrar', NormalizadorCitaMedicaTest::entradaWeb())->assertCreated();

        $this->postJson(self::URL.'/movil/registrar', NormalizadorCitaMedicaTest::entradaMovil())
            ->assertStatus(409);

        $this->assertSame(1, Agenda::count());
    }

    public function test_contrato_valido_pero_paciente_inexistente_devuelve_422_sin_guardar(): void
    {
        $entrada = ['codigoPaciente' => '999'] + NormalizadorCitaMedicaTest::entradaWeb();

        $this->postJson(self::URL.'/web/registrar', $entrada)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo_paciente']);

        $this->assertSame(0, Agenda::count());
    }

    public function test_origen_no_soportado_devuelve_404(): void
    {
        $this->postJson(self::URL.'/fax', [])->assertNotFound();
    }

    public function test_rol_sin_permiso_recibe_403(): void
    {
        Sanctum::actingAs(new User(['role' => 'paciente']));

        $this->postJson(self::URL.'/web', NormalizadorCitaMedicaTest::entradaWeb())->assertForbidden();
    }
}
