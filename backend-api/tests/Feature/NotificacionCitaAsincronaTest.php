<?php

namespace Tests\Feature;

use App\Mensajeria\BrokerNoDisponibleException;
use App\Mensajeria\ConexionRabbitMq;
use App\Mensajeria\MensajeNotificacionCita;
use App\Mensajeria\PublicadorMensajes;
use App\Mensajeria\RabbitMqPublicador;
use App\Models\Agenda;
use App\Models\NotificacionCita;
use App\Models\Paciente;
use App\Models\User;
use App\Services\Asincrono\ProcesadorMensajeNotificacion;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Throwable;

/**
 * Práctica 3.1 (William Cabrera): productor, cola y consumidor de la
 * notificación de cita médica, con correlation_id.
 */
class NotificacionCitaAsincronaTest extends TestCase
{
    private const URL = '/api/mensajeria/citas-medicas/notificaciones';

    private PublicadorFalso $publicador;

    private Agenda $cita;

    private string $log;

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
            '2026_11_20_000001_create_notificaciones_cita_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        config(['services.notificaciones.latencia_ms' => 0]);

        $this->log = tempnam(sys_get_temp_dir(), 'mensajeria');
        config(['logging.channels.mensajeria.path' => $this->log]);

        $this->publicador = new PublicadorFalso;
        $this->app->instance(PublicadorMensajes::class, $this->publicador);

        $paciente = Paciente::create([
            'codigo_paciente' => '007',
            'nombres' => 'Paciente Ficticio',
            'cedula' => '0000000007',
            'telefono' => '0990000007',
        ]);

        User::create([
            'name' => 'Paciente Ficticio',
            'email' => 'paciente007@example.test',
            'username' => 'paciente007',
            'password' => 'clave-ficticia',
            'role' => 'paciente',
            'paciente_id' => $paciente->id,
        ]);

        $this->cita = Agenda::create([
            'paciente_id' => $paciente->id,
            'fecha' => '2026-11-27',
            'hora_inicio' => '09:00',
            'hora_fin' => '09:30',
            'tipo' => 'cita_medica',
            'descripcion' => 'Limpieza dental',
            'estado' => 'programada',
        ]);

        Sanctum::actingAs(User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora@example.test',
            'username' => 'doctora',
            'password' => 'clave-ficticia',
            'role' => 'doctora',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    // Prueba 1: mensaje válido

    public function test_mensaje_valido_responde_202_y_queda_publicado(): void
    {
        $respuesta = $this->postJson(self::URL, $this->entrada())
            ->assertStatus(202)
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonStructure(['message', 'estado', 'message_id', 'correlation_id', 'seguimiento']);

        $correlationId = $respuesta->json('correlation_id');
        $respuesta->assertHeader('X-Correlation-ID', $correlationId);

        $this->assertCount(1, $this->publicador->mensajes);
        $mensaje = $this->publicador->mensajes[0]->aArreglo();
        $this->assertSame($correlationId, $mensaje['correlation_id']);
        $this->assertSame($respuesta->json('message_id'), $mensaje['message_id']);
        $this->assertSame('notificacion_cita_registrada', $mensaje['tipo']);
        $this->assertSame(['cita_id', 'codigo_paciente', 'fecha', 'hora_inicio', 'canal'], array_keys($mensaje['payload']));

        $registro = NotificacionCita::sole();
        $this->assertSame('pendiente', $registro->estado);
        $this->assertNotNull($registro->publicada_en);
        $this->assertNull($registro->procesada_en);
    }

    public function test_el_consumidor_procesa_el_mensaje_y_lo_marca_enviado(): void
    {
        $this->postJson(self::URL, $this->entrada())->assertStatus(202);

        $resultado = $this->consumir($this->publicador->mensajes[0]);

        $this->assertSame(ProcesadorMensajeNotificacion::ACK, $resultado);
        $registro = NotificacionCita::sole();
        $this->assertSame('enviada', $registro->estado);
        $this->assertSame(1, $registro->intentos);
        $this->assertSame('p***@example.test', $registro->destino);
        $this->assertStringContainsString('27/11/2026 a las 09:00', $registro->mensaje);
        $this->assertNotNull($registro->procesada_en);
    }

    public function test_tres_mensajes_se_procesan_por_separado(): void
    {
        foreach (['correo', 'sms', 'correo'] as $canal) {
            $this->postJson(self::URL, $this->entrada(['canal' => $canal]))->assertStatus(202);
        }

        foreach ($this->publicador->mensajes as $mensaje) {
            $this->assertSame(ProcesadorMensajeNotificacion::ACK, $this->consumir($mensaje));
        }

        $this->assertSame(3, NotificacionCita::where('estado', 'enviada')->count());
        $this->assertSame(3, NotificacionCita::distinct()->count('correlation_id'));
        $this->assertSame('09******07', NotificacionCita::where('canal', 'sms')->value('destino'));
    }

    // Prueba 2: consumidor detenido (el mensaje espera en estado pendiente)

    public function test_sin_consumidor_la_api_responde_igual_y_el_mensaje_queda_pendiente(): void
    {
        $correlationId = $this->postJson(self::URL, $this->entrada())->assertStatus(202)->json('correlation_id');

        $this->getJson(self::URL.'/'.$correlationId)
            ->assertOk()
            ->assertJsonPath('data.0.estado', 'pendiente');

        $this->consumir($this->publicador->mensajes[0]);

        $this->getJson(self::URL.'/'.$correlationId)
            ->assertOk()
            ->assertJsonPath('data.0.estado', 'enviada');
    }

    // Prueba 3: mensaje inválido

    public function test_mensaje_incompleto_devuelve_422_y_no_se_publica(): void
    {
        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cita_id', 'canal']);

        $this->postJson(self::URL, ['cita_id' => 'no-es-uuid', 'canal' => 'fax'])
            ->assertStatus(422)
            ->assertJsonPath('errors.cita_id.0', 'cita_id debe ser un UUID.')
            ->assertJsonPath('errors.canal.0', 'El canal debe ser correo o sms.');

        $this->postJson(self::URL, $this->entrada(['cita_id' => '01a1faae-0000-7000-8000-000000000000']))
            ->assertStatus(422)
            ->assertJsonPath('errors.cita_id.0', 'No existe una cita médica con ese cita_id.');

        $this->assertSame([], $this->publicador->mensajes);
        $this->assertSame(0, NotificacionCita::count());
    }

    public function test_el_consumidor_rechaza_un_mensaje_mal_formado(): void
    {
        $procesador = $this->app->make(ProcesadorMensajeNotificacion::class);

        $this->assertSame(ProcesadorMensajeNotificacion::RECHAZAR, $procesador->procesar('{no es json', 'corr-roto-001'));
        $this->assertSame(ProcesadorMensajeNotificacion::RECHAZAR, $procesador->procesar(
            json_encode(['message_id' => 'x', 'correlation_id' => 'y', 'tipo' => 'otro', 'ocurrido_en' => 'hoy', 'payload' => []])
        ));
        $this->assertStringContainsString('consumidor.mensaje_invalido', file_get_contents($this->log));
    }

    // Prueba 4: correlación

    public function test_el_mismo_correlation_id_aparece_en_productor_consumidor_y_registro(): void
    {
        $correlationId = 'web-prueba-correlacion-001';

        $this->withHeader('X-Correlation-ID', $correlationId)
            ->postJson(self::URL, $this->entrada())
            ->assertStatus(202)
            ->assertJsonPath('correlation_id', $correlationId);

        $this->consumir($this->publicador->mensajes[0]);

        $eventos = collect(file($this->log, FILE_IGNORE_NEW_LINES))
            ->map(fn ($linea) => json_decode($linea, true))
            ->filter(fn ($e) => ($e['context']['correlation_id'] ?? null) === $correlationId)
            ->pluck('message')
            ->values()
            ->all();

        $this->assertSame([
            'productor.solicitud_recibida',
            'productor.mensaje_publicado',
            'consumidor.mensaje_recibido',
            'proveedor.envio_simulado',
            'notificacion.enviada',
        ], $eventos);

        $this->assertSame($correlationId, NotificacionCita::sole()->correlation_id);
    }

    public function test_un_correlation_id_invalido_se_reemplaza_por_uno_nuevo(): void
    {
        $correlationId = $this->withHeader('X-Correlation-ID', 'mal <script>')
            ->postJson(self::URL, $this->entrada())
            ->json('correlation_id');

        $this->assertMatchesRegularExpression('/^cita-[0-9a-f-]{36}$/', $correlationId);
    }

    // Errores y casos borde

    public function test_si_el_broker_no_esta_disponible_responde_503_y_queda_no_publicada(): void
    {
        $this->publicador->fallar = true;

        $this->postJson(self::URL, $this->entrada())
            ->assertStatus(503)
            ->assertJsonPath('estado', 'no_publicada')
            ->assertHeader('Retry-After', '30');

        $this->assertSame('no_publicada', NotificacionCita::sole()->estado);
    }

    public function test_un_mensaje_entregado_dos_veces_no_avisa_dos_veces(): void
    {
        $this->postJson(self::URL, $this->entrada())->assertStatus(202);
        $mensaje = $this->publicador->mensajes[0];

        $this->assertSame(ProcesadorMensajeNotificacion::ACK, $this->consumir($mensaje));
        $this->assertSame(ProcesadorMensajeNotificacion::ACK, $this->consumir($mensaje));

        $this->assertSame(1, NotificacionCita::sole()->intentos);
        $this->assertStringContainsString('consumidor.duplicado_ignorado', file_get_contents($this->log));
    }

    public function test_si_la_cita_se_cancela_antes_de_procesar_se_descarta(): void
    {
        $this->postJson(self::URL, $this->entrada())->assertStatus(202);
        $this->cita->update(['estado' => 'cancelada']);

        $this->assertSame(ProcesadorMensajeNotificacion::ACK, $this->consumir($this->publicador->mensajes[0]));
        $this->assertSame('descartada', NotificacionCita::sole()->estado);
    }

    // Comparación: versión síncrona

    public function test_la_version_sincrona_responde_cuando_ya_termino(): void
    {
        $this->postJson(self::URL.'/sincrona', $this->entrada())
            ->assertOk()
            ->assertJsonPath('estado', 'enviada');

        $this->assertSame([], $this->publicador->mensajes);
        $this->assertSame('sincrono', NotificacionCita::sole()->modo);
    }

    public function test_solo_la_doctora_puede_usar_los_endpoints(): void
    {
        Sanctum::actingAs(User::where('role', 'paciente')->first());
        $this->postJson(self::URL, $this->entrada())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson(self::URL, $this->entrada(), ['Authorization' => ''])->assertUnauthorized();
    }

    // Integración con RabbitMQ real (se omite si el broker no está disponible)

    public function test_integracion_rabbitmq_productor_cola_consumidor(): void
    {
        config([
            'rabbitmq.notificaciones_cita.cola' => 'clinica.citas.notificaciones.test',
            'rabbitmq.notificaciones_cita.routing_key' => 'notificacion.test',
            'rabbitmq.user' => env('RABBITMQ_USER', config('rabbitmq.user')),
            'rabbitmq.password' => env('RABBITMQ_PASSWORD', config('rabbitmq.password')),
        ]);

        try {
            $conexion = ConexionRabbitMq::abrir();
            $canal = $conexion->channel();
            ConexionRabbitMq::declararTopologia($canal);
            $canal->queue_purge('clinica.citas.notificaciones.test');
        } catch (Throwable $e) {
            $this->markTestSkipped('RabbitMQ no disponible: '.$e->getMessage());
        }

        $this->app->instance(PublicadorMensajes::class, new RabbitMqPublicador);

        $correlationId = $this->postJson(self::URL, $this->entrada())->assertStatus(202)->json('correlation_id');

        [, $enCola] = $canal->queue_declare('clinica.citas.notificaciones.test', true);
        $this->assertSame(1, $enCola);
        $this->assertSame('pendiente', NotificacionCita::sole()->estado);

        Artisan::call('citas:consumir-notificaciones', ['--max' => 1]);

        $this->assertSame('enviada', NotificacionCita::sole()->estado);
        $this->assertStringContainsString($correlationId, Artisan::output());

        [, $enCola] = $canal->queue_declare('clinica.citas.notificaciones.test', true);
        $this->assertSame(0, $enCola);

        $canal->queue_delete('clinica.citas.notificaciones.test');
        $canal->close();
        $conexion->close();
    }

    private function entrada(array $cambios = []): array
    {
        return $cambios + ['cita_id' => $this->cita->id, 'canal' => 'correo'];
    }

    private function consumir(MensajeNotificacionCita $mensaje): string
    {
        return $this->app->make(ProcesadorMensajeNotificacion::class)
            ->procesar($mensaje->aJson(), $mensaje->correlationId);
    }
}

class PublicadorFalso implements PublicadorMensajes
{
    /** @var MensajeNotificacionCita[] */
    public array $mensajes = [];

    public bool $fallar = false;

    public function publicar(MensajeNotificacionCita $mensaje): void
    {
        if ($this->fallar) {
            throw new BrokerNoDisponibleException('Broker de prueba caído.');
        }

        $this->mensajes[] = $mensaje;
    }
}
