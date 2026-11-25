<?php

namespace Tests\Feature;

use App\Mensajeria\BrokerNoDisponibleException;
use App\Mensajeria\Enrutamiento\Destino;
use App\Mensajeria\Enrutamiento\EnrutadorNotificaciones;
use App\Mensajeria\Enrutamiento\MensajeNotificacion;
use App\Mensajeria\Enrutamiento\PublicadorEnrutado;
use App\Models\Agenda;
use App\Models\MensajeEnrutado;
use App\Models\Paciente;
use App\Models\User;
use App\Services\Enrutamiento\EntregaAConsumidor;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Práctica 3.2 (William Cabrera): Content-Based Router y Message Filter sobre
 * las notificaciones de citas médicas. Los casos 1–6 son los obligatorios de
 * la guía; el resto cubre los bordes (duplicados, broker caído, consumidor
 * equivocado).
 */
class EnrutamientoNotificacionesTest extends TestCase
{
    private const URL = '/api/enrutamiento/notificaciones';

    private PublicadorEnrutadoFalso $publicador;

    private Agenda $programada;

    private Agenda $cancelada;

    private Agenda $pasada;

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
            '2026_11_10_202239_add_nombre_reserva_to_agenda_table.php',
            '2026_11_24_000001_create_mensajes_enrutados_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        config(['app.timezone' => 'America/Guayaquil', 'services.notificaciones.latencia_ms' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 09:00', 'America/Guayaquil'));

        $this->publicador = new PublicadorEnrutadoFalso;
        $this->app->instance(PublicadorEnrutado::class, $this->publicador);

        $paciente = Paciente::create([
            'codigo_paciente' => '007', 'nombres' => 'Paciente Ficticio', 'cedula' => '0000000007',
        ]);

        $this->programada = $this->cita($paciente, 'programada', '2026-11-26');
        $this->cancelada = $this->cita($paciente, 'cancelada', '2026-11-27');
        $this->pasada = $this->cita($paciente, 'programada', '2026-11-20');

        Sanctum::actingAs(User::create([
            'name' => 'Doctora de prueba', 'email' => 'doctora@example.test', 'username' => 'doctora',
            'password' => 'clave-ficticia', 'role' => 'doctora',
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cita(Paciente $paciente, string $estado, string $fecha): Agenda
    {
        return Agenda::create([
            'paciente_id' => $paciente->id, 'tipo' => 'cita_medica', 'estado' => $estado,
            'fecha' => $fecha, 'hora_inicio' => '10:00', 'hora_fin' => '10:30', 'descripcion' => 'Limpieza dental',
        ]);
    }

    private function mensaje(string $id, string $tipo, ?Agenda $cita = null, string $prioridad = 'media'): array
    {
        return [
            'id' => $id,
            'tipo' => $tipo,
            'prioridad' => $prioridad,
            'correlation_id' => "corr-{$id}",
            'datos' => ['cita_id' => ($cita ?? $this->programada)->id, 'canal' => 'correo'],
        ];
    }

    // Casos 1–3: tres tipos válidos, cada uno a su consumidor.

    public function test_caso_1_confirmacion_va_solo_a_confirmaciones(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0001', 'confirmacion', prioridad: 'alta'))
            ->assertStatus(202)
            ->assertJsonPath('decision', 'enrutado')
            ->assertJsonPath('regla', 'R1')
            ->assertJsonPath('destino', 'confirmaciones')
            ->assertJsonPath('routing_key', 'notificacion.confirmacion')
            ->assertJsonPath('cola', 'clinica.notificaciones.confirmaciones')
            ->assertHeader('X-Correlation-ID', 'corr-NOT-0001');

        $this->assertSame(['NOT-0001' => 'notificacion.confirmacion'], $this->publicador->rutas());
        $this->assertSame(9, $this->publicador->publicados[0]['prioridad_amqp']);
    }

    public function test_caso_2_recordatorio_va_solo_a_recordatorios(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0002', 'recordatorio'))
            ->assertStatus(202)
            ->assertJsonPath('regla', 'R2')
            ->assertJsonPath('destino', 'recordatorios');

        $this->assertSame(['NOT-0002' => 'notificacion.recordatorio'], $this->publicador->rutas());
    }

    public function test_caso_3_cancelacion_va_solo_a_cancelaciones(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0003', 'cancelacion', $this->cancelada, 'baja'))
            ->assertStatus(202)
            ->assertJsonPath('regla', 'R3')
            ->assertJsonPath('destino', 'cancelaciones');

        $this->assertSame(['NOT-0003' => 'notificacion.cancelacion'], $this->publicador->rutas());
        $this->assertSame(1, $this->publicador->publicados[0]['prioridad_amqp']);
    }

    // Caso 4: tipo desconocido.

    public function test_caso_4_tipo_desconocido_va_a_no_enrutables_sin_perderse(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0004', 'promocion'))
            ->assertStatus(202)
            ->assertJsonPath('decision', 'no_enrutable')
            ->assertJsonPath('regla', 'R0')
            ->assertJsonPath('destino', 'no_enrutables')
            ->assertJsonPath('motivo', 'Tipo no reconocido: promocion.');

        $this->assertSame(['NOT-0004' => 'notificacion.no_enrutable'], $this->publicador->rutas());
    }

    // Caso 5: no cumple el filtro.

    public function test_caso_5_confirmacion_de_cita_cancelada_se_filtra_y_no_se_publica(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0005', 'confirmacion', $this->cancelada))
            ->assertOk()
            ->assertJsonPath('decision', 'filtrado')
            ->assertJsonPath('regla', 'F3')
            ->assertJsonPath('destino', null);

        $this->assertSame([], $this->publicador->publicados);
        $this->assertDatabaseHas('mensajes_enrutados', [
            'mensaje_id' => 'NOT-0005', 'decision' => 'filtrado', 'regla' => 'F3', 'destino' => null,
        ]);
    }

    public function test_filtro_f2_no_notifica_una_cita_que_ya_paso(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0006', 'recordatorio', $this->pasada))
            ->assertOk()
            ->assertJsonPath('decision', 'filtrado')
            ->assertJsonPath('regla', 'F2');

        $this->assertSame([], $this->publicador->publicados);
    }

    public function test_filtro_f1_no_notifica_una_cita_inexistente(): void
    {
        $entrada = $this->mensaje('NOT-0007', 'confirmacion');
        $entrada['datos']['cita_id'] = '00000000-0000-4000-8000-000000000000';

        $this->postJson(self::URL, $entrada)
            ->assertOk()
            ->assertJsonPath('decision', 'filtrado')
            ->assertJsonPath('regla', 'F1');

        $this->assertSame([], $this->publicador->publicados);
    }

    // Caso 6: falta un campo obligatorio.

    public function test_caso_6_sin_campo_obligatorio_se_rechaza_de_forma_controlada(): void
    {
        $entrada = $this->mensaje('NOT-0008', 'confirmacion');
        unset($entrada['prioridad']);

        $this->postJson(self::URL, $entrada)
            ->assertStatus(422)
            ->assertJsonPath('decision', 'invalido')
            ->assertJsonValidationErrors(['prioridad']);

        $this->assertSame([], $this->publicador->publicados);
        $this->assertDatabaseHas('mensajes_enrutados', ['mensaje_id' => 'NOT-0008', 'decision' => 'invalido', 'regla' => 'V']);
    }

    public function test_sin_datos_de_la_cita_tambien_es_invalido(): void
    {
        $entrada = $this->mensaje('NOT-0009', 'confirmacion');
        unset($entrada['datos']);

        $this->postJson(self::URL, $entrada)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['datos', 'datos.cita_id', 'datos.canal']);
    }

    // Bordes.

    public function test_un_mensaje_repetido_no_se_vuelve_a_enrutar(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0010', 'confirmacion'))->assertStatus(202);
        $this->postJson(self::URL, $this->mensaje('NOT-0010', 'confirmacion'))
            ->assertStatus(409)
            ->assertJsonPath('anterior.destino', 'confirmaciones');

        $this->assertCount(1, $this->publicador->publicados);
    }

    public function test_broker_caido_es_error_de_transporte_y_no_filtrado(): void
    {
        $this->publicador->fallar = true;

        $this->postJson(self::URL, $this->mensaje('NOT-0011', 'confirmacion'))
            ->assertStatus(503)
            ->assertJsonPath('decision', 'error_transporte')
            ->assertJsonPath('destino', 'confirmaciones');

        // Se puede reintentar el mismo id cuando vuelva el broker.
        $this->publicador->fallar = false;
        $this->postJson(self::URL, $this->mensaje('NOT-0011', 'confirmacion'))->assertStatus(202);
    }

    public function test_sin_correlation_id_usa_la_cabecera(): void
    {
        $entrada = $this->mensaje('NOT-0012', 'recordatorio');
        unset($entrada['correlation_id']);

        $this->postJson(self::URL, $entrada, ['X-Correlation-ID' => 'cabecera-0012'])
            ->assertStatus(202)
            ->assertJsonPath('correlation_id', 'cabecera-0012');
    }

    public function test_reglas_y_bitacora_se_pueden_consultar(): void
    {
        $this->getJson('/api/enrutamiento/reglas')
            ->assertOk()
            ->assertJsonCount(4, 'enrutamiento')
            ->assertJsonPath('enrutamiento.3.destino', 'no_enrutables')
            ->assertJsonStructure(['filtro' => ['F1', 'F2', 'F3']]);

        $this->postJson(self::URL, $this->mensaje('NOT-0013', 'confirmacion'));
        $this->getJson(self::URL.'/NOT-0013')
            ->assertOk()
            ->assertJsonPath('registros.0.decision', 'enrutado');
        $this->getJson(self::URL.'/NO-EXISTE')->assertNotFound();
    }

    public function test_solo_la_doctora_puede_enrutar(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Paciente', 'email' => 'p@example.test', 'username' => 'p1',
            'password' => 'clave-ficticia', 'role' => 'paciente',
        ]));

        $this->postJson(self::URL, $this->mensaje('NOT-0014', 'confirmacion'))->assertForbidden();
    }

    // Lado consumidor.

    public function test_cada_consumidor_atiende_su_mensaje_y_lo_deja_en_la_bitacora(): void
    {
        $casos = [
            ['NOT-0020', 'confirmacion', $this->programada, 'confirmaciones', 'consumidor_confirmaciones', 'quedó confirmada'],
            ['NOT-0021', 'recordatorio', $this->programada, 'recordatorios', 'consumidor_recordatorios', 'le recordamos'],
            ['NOT-0022', 'cancelacion', $this->cancelada, 'cancelaciones', 'consumidor_cancelaciones', 'fue cancelada'],
            ['NOT-0023', 'promocion', $this->programada, 'no_enrutables', 'revision_no_enrutables', 'Pendiente de revisión'],
        ];

        foreach ($casos as [$id, $tipo, $cita, $destino, $consumidor, $texto]) {
            $this->postJson(self::URL, $this->mensaje($id, $tipo, $cita))->assertStatus(202);

            $cuerpo = $this->publicador->cuerpo($id);
            $this->assertSame(EntregaAConsumidor::ACK, app(EntregaAConsumidor::class)->procesar($destino, $cuerpo));

            $registro = MensajeEnrutado::where('mensaje_id', $id)->sole();
            $this->assertSame($consumidor, $registro->consumido_por);
            $this->assertNotNull($registro->consumido_en);
            $this->assertStringContainsString($texto, $registro->resultado);
        }
    }

    public function test_un_consumidor_rechaza_el_mensaje_que_no_le_corresponde(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0030', 'confirmacion'))->assertStatus(202);

        $this->assertSame(EntregaAConsumidor::RECHAZAR,
            app(EntregaAConsumidor::class)->procesar('cancelaciones', $this->publicador->cuerpo('NOT-0030')));
        $this->assertNull(MensajeEnrutado::where('mensaje_id', 'NOT-0030')->sole()->consumido_por);
    }

    public function test_el_consumidor_no_atiende_dos_veces_el_mismo_mensaje(): void
    {
        $this->postJson(self::URL, $this->mensaje('NOT-0031', 'recordatorio'))->assertStatus(202);
        $entrega = app(EntregaAConsumidor::class);
        $cuerpo = $this->publicador->cuerpo('NOT-0031');

        $this->assertSame(EntregaAConsumidor::ACK, $entrega->procesar('recordatorios', $cuerpo));
        $primera = MensajeEnrutado::where('mensaje_id', 'NOT-0031')->sole()->consumido_en;

        $this->assertSame(EntregaAConsumidor::ACK, $entrega->procesar('recordatorios', $cuerpo));
        $this->assertEquals($primera, MensajeEnrutado::where('mensaje_id', 'NOT-0031')->sole()->consumido_en);
    }

    public function test_el_consumidor_rechaza_un_cuerpo_danado(): void
    {
        $this->assertSame(EntregaAConsumidor::RECHAZAR,
            app(EntregaAConsumidor::class)->procesar('confirmaciones', '{"id":"X"'));
    }

    public function test_el_enrutador_cubre_los_tres_tipos_y_el_resto_va_a_no_enrutables(): void
    {
        $enrutador = new EnrutadorNotificaciones;
        $mensaje = fn (string $tipo) => new MensajeNotificacion('ID-1', $tipo, 'media', 'corr-00001',
            $this->programada->id, 'sms', now()->toImmutable());

        $this->assertSame('confirmaciones', $enrutador->enrutar($mensaje('confirmacion'))->nombre);
        $this->assertSame('recordatorios', $enrutador->enrutar($mensaje('recordatorio'))->nombre);
        $this->assertSame('cancelaciones', $enrutador->enrutar($mensaje('cancelacion'))->nombre);
        $this->assertTrue($enrutador->enrutar($mensaje('pago'))->esNoEnrutable());
    }
}

/**
 * Sustituye a RabbitMQ en las pruebas: guarda qué se publicó y con qué routing key.
 */
class PublicadorEnrutadoFalso implements PublicadorEnrutado
{
    public array $publicados = [];

    public bool $fallar = false;

    public function publicar(MensajeNotificacion $mensaje, Destino $destino): void
    {
        if ($this->fallar) {
            throw new BrokerNoDisponibleException('Connection refused');
        }

        $this->publicados[] = [
            'id' => $mensaje->id,
            'routing_key' => $destino->routingKey,
            'prioridad_amqp' => $mensaje->prioridadAmqp(),
            'cuerpo' => $mensaje->aJson(),
        ];
    }

    public function rutas(): array
    {
        return array_column($this->publicados, 'routing_key', 'id');
    }

    public function cuerpo(string $id): string
    {
        return array_column($this->publicados, 'cuerpo', 'id')[$id];
    }
}
