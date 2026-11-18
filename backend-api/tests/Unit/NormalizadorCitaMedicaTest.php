<?php

namespace Tests\Unit;

use App\Exceptions\Normalizacion\EntradaCitaInvalidaException;
use App\Services\Normalizacion\ContratoCitaMedica;
use App\Services\Normalizacion\NormalizadorCitaMedica;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del traductor de la Práctica 2.3 (William Cabrera), sin base de datos.
 *
 * P1: entrada A (formulario web) válida  -> contrato común correcto.
 * P2: entrada B (app móvil) equivalente  -> mismo significado y tipos que P1.
 * P3: campo faltante o valor inválido    -> error de validación explícito.
 */
class NormalizadorCitaMedicaTest extends TestCase
{
    private const CONTRATO_ESPERADO = [
        'codigo_paciente' => '007',
        'fecha' => '2026-11-20',
        'hora_inicio' => '09:00',
        'hora_fin' => '09:30',
        'descripcion' => 'Limpieza dental',
    ];

    private NormalizadorCitaMedica $normalizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizador = new NormalizadorCitaMedica;
    }

    /** @return array<string, mixed> */
    public static function entradaWeb(): array
    {
        return [
            'codigoPaciente' => ' 007 ',
            'fechaCita' => '20/11/2026',
            'horaInicio' => '9:00',
            'horaFin' => '9:30',
            'motivo' => '  Limpieza   dental ',
        ];
    }

    /** @return array<string, mixed> */
    public static function entradaMovil(): array
    {
        return [
            'patient' => ['code' => 7],
            'appointment' => [
                'date' => '2026-11-20',
                'startTime' => '09:00 AM',
                'durationMinutes' => 30,
                'notes' => 'Limpieza dental',
            ],
        ];
    }

    public function test_p1_entrada_web_valida_produce_el_contrato_comun(): void
    {
        $contrato = $this->normalizador->desdeFormularioWeb(self::entradaWeb());

        $this->assertSame(self::CONTRATO_ESPERADO, $contrato);
        $this->assertSame(ContratoCitaMedica::CAMPOS, array_keys($contrato));
    }

    public function test_p2_entrada_movil_equivalente_produce_el_mismo_contrato_y_tipos(): void
    {
        $desdeWeb = $this->normalizador->normalizar('web', self::entradaWeb());
        $desdeMovil = $this->normalizador->normalizar('movil', self::entradaMovil());

        // assertSame compara valores y tipos (el 7 entero pasa a "007" texto).
        $this->assertSame(self::CONTRATO_ESPERADO, $desdeMovil);
        $this->assertSame($desdeWeb, $desdeMovil);
    }

    public function test_hora_de_tarde_en_formato_12h_se_convierte_a_24h(): void
    {
        $entrada = self::entradaMovil();
        $entrada['appointment']['startTime'] = '2:45 pm';
        $entrada['appointment']['durationMinutes'] = 45;

        $contrato = $this->normalizador->desdeAppMovil($entrada);

        $this->assertSame('14:45', $contrato['hora_inicio']);
        $this->assertSame('15:30', $contrato['hora_fin']);
    }

    public function test_descripcion_vacia_se_normaliza_a_null(): void
    {
        $entrada = self::entradaWeb();
        $entrada['motivo'] = '   ';

        $this->assertNull($this->normalizador->desdeFormularioWeb($entrada)['descripcion']);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function entradasInvalidas(): array
    {
        $web = self::entradaWeb();
        $movil = self::entradaMovil();

        $sinCodigo = $web;
        unset($sinCodigo['codigoPaciente']);

        $sinFecha = $movil;
        unset($sinFecha['appointment']['date']);

        return [
            'P3 web: falta codigoPaciente' => ['web', $sinCodigo, 'codigoPaciente'],
            'P3 movil: falta appointment.date' => ['movil', $sinFecha, 'appointment.date'],
            'web: fecha en formato ISO' => ['web', ['fechaCita' => '2026-11-20'] + $web, 'fechaCita'],
            'web: fecha inexistente 31/02' => ['web', ['fechaCita' => '31/02/2026'] + $web, 'fechaCita'],
            'web: hora 25:00' => ['web', ['horaInicio' => '25:00'] + $web, 'horaInicio'],
            'web: hora fin antes de inicio' => ['web', ['horaFin' => '8:30'] + $web, 'horaFin'],
            'web: código con letras' => ['web', ['codigoPaciente' => 'PAC-7'] + $web, 'codigoPaciente'],
            'web: código cero' => ['web', ['codigoPaciente' => '000'] + $web, 'codigoPaciente'],
            'web: campo de otro origen' => ['web', $web + ['durationMinutes' => 30], 'durationMinutes'],
            'movil: código como texto' => ['movil', ['patient' => ['code' => '7']] + $movil, 'patient.code'],
            'movil: código decimal' => ['movil', ['patient' => ['code' => 7.5]] + $movil, 'patient.code'],
            'movil: hora sin AM/PM' => ['movil', self::conCita($movil, 'startTime', '09:00'), 'appointment.startTime'],
            'movil: hora 13:00 PM' => ['movil', self::conCita($movil, 'startTime', '13:00 PM'), 'appointment.startTime'],
            'movil: duración en texto' => ['movil', self::conCita($movil, 'durationMinutes', '30'), 'appointment.durationMinutes'],
            'movil: duración 0' => ['movil', self::conCita($movil, 'durationMinutes', 0), 'appointment.durationMinutes'],
            'movil: termina al día siguiente' => ['movil', self::conCita(self::conCita($movil, 'startTime', '11:45 PM'), 'durationMinutes', 30), 'appointment.durationMinutes'],
            'movil: hora fin y duración (ambiguo)' => ['movil', self::conCita($movil, 'endTime', '09:30 AM'), 'appointment.endTime'],
            'movil: patient no es objeto' => ['movil', ['patient' => 7] + $movil, 'patient'],
            'movil: notes no es texto' => ['movil', self::conCita($movil, 'notes', 123), 'appointment.notes'],
        ];
    }

    /** @param array<string, mixed> $entrada */
    #[DataProvider('entradasInvalidas')]
    public function test_p3_entrada_invalida_lanza_error_explicito(string $origen, array $entrada, string $campo): void
    {
        try {
            $this->normalizador->normalizar($origen, $entrada);
            $this->fail('Se esperaba EntradaCitaInvalidaException.');
        } catch (EntradaCitaInvalidaException $e) {
            $this->assertSame($origen, $e->origen);
            $this->assertArrayHasKey($campo, $e->errores, json_encode($e->errores, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_p3_acumula_todos_los_errores_en_lugar_de_detenerse_en_el_primero(): void
    {
        try {
            $this->normalizador->desdeFormularioWeb(['fechaCita' => '2026/11/20', 'horaInicio' => 'nueve']);
            $this->fail('Se esperaba EntradaCitaInvalidaException.');
        } catch (EntradaCitaInvalidaException $e) {
            $this->assertEqualsCanonicalizing(
                ['codigoPaciente', 'fechaCita', 'horaInicio', 'horaFin'],
                array_keys($e->errores)
            );
        }
    }

    public function test_origen_desconocido_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizador->normalizar('fax', []);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private static function conCita(array $entrada, string $campo, mixed $valor): array
    {
        $entrada['appointment'][$campo] = $valor;

        return $entrada;
    }
}
