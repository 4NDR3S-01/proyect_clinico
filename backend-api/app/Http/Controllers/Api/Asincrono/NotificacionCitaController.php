<?php

namespace App\Http\Controllers\Api\Asincrono;

use App\Http\Controllers\Controller;
use App\Mensajeria\BrokerNoDisponibleException;
use App\Mensajeria\MensajeNotificacionCita;
use App\Mensajeria\PublicadorMensajes;
use App\Models\Agenda;
use App\Models\NotificacionCita;
use App\Services\Asincrono\NotificadorCitaMedica;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Práctica 3.1 (William Cabrera): aviso al paciente cuando se registra su cita.
 *
 * - asincrono(): productor. Valida, guarda el estado "pendiente", publica en
 *   RabbitMQ y responde 202 sin esperar el envío.
 * - sincrono(): el mismo trabajo dentro de la petición, para comparar tiempos.
 * - estado(): seguimiento de la operación por su correlation_id.
 */
class NotificacionCitaController extends Controller
{
    public const CABECERA = 'X-Correlation-ID';

    public function asincrono(Request $request, PublicadorMensajes $publicador): JsonResponse
    {
        $datos = $this->validar($request);
        $correlationId = $this->correlationId($request);
        $mensaje = $this->mensaje($datos, $correlationId);

        $registro = $this->registrar($mensaje, 'asincrono');

        Log::channel('mensajeria')->info('productor.solicitud_recibida', [
            'correlation_id' => $correlationId,
            'message_id' => $mensaje->messageId,
            'cita_id' => $datos['cita_id'],
        ]);

        try {
            $publicador->publicar($mensaje);
        } catch (BrokerNoDisponibleException $e) {
            $registro->update(['estado' => NotificacionCita::NO_PUBLICADA, 'detalle' => 'Broker no disponible']);

            Log::channel('mensajeria')->error('productor.no_publicado', [
                'correlation_id' => $correlationId,
                'message_id' => $mensaje->messageId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo encolar la notificación. Intenta nuevamente en unos minutos.',
                'correlation_id' => $correlationId,
                'estado' => NotificacionCita::NO_PUBLICADA,
            ], 503, [self::CABECERA => $correlationId, 'Retry-After' => '30']);
        }

        $registro->update(['publicada_en' => now()]);

        Log::channel('mensajeria')->info('productor.mensaje_publicado', [
            'correlation_id' => $correlationId,
            'message_id' => $mensaje->messageId,
            'cola' => config('rabbitmq.notificaciones_cita.cola'),
        ]);

        return response()->json([
            'message' => 'Solicitud recibida. La notificación se enviará en segundo plano.',
            'estado' => NotificacionCita::PENDIENTE,
            'message_id' => $mensaje->messageId,
            'correlation_id' => $correlationId,
            'seguimiento' => url('/api/mensajeria/citas-medicas/notificaciones/'.$correlationId),
        ], 202, [self::CABECERA => $correlationId]);
    }

    public function sincrono(Request $request, NotificadorCitaMedica $notificador): JsonResponse
    {
        $datos = $this->validar($request);
        $correlationId = $this->correlationId($request);
        $mensaje = $this->mensaje($datos, $correlationId);

        Log::channel('mensajeria')->info('sincrono.solicitud_recibida', [
            'correlation_id' => $correlationId,
            'message_id' => $mensaje->messageId,
        ]);

        $registro = $notificador->procesar($this->registrar($mensaje, 'sincrono'));

        return response()->json([
            'message' => 'Notificación procesada.',
            'estado' => $registro->estado,
            'message_id' => $registro->message_id,
            'correlation_id' => $correlationId,
        ], 200, [self::CABECERA => $correlationId]);
    }

    public function estado(string $correlationId): JsonResponse
    {
        $registros = NotificacionCita::where('correlation_id', $correlationId)
            ->orderBy('created_at')
            ->get();

        if ($registros->isEmpty()) {
            return response()->json(['message' => 'No hay notificaciones con ese correlation_id.'], 404);
        }

        return response()->json(['data' => $registros->map->aSeguimiento()->values()]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'cita_id' => [
                'required', 'uuid',
                Rule::exists(Agenda::class, 'id')->where('tipo', 'cita_medica'),
            ],
            'canal' => ['required', Rule::in(MensajeNotificacionCita::CANALES)],
        ], [
            'cita_id.required' => 'Indica la cita (cita_id).',
            'cita_id.uuid' => 'cita_id debe ser un UUID.',
            'cita_id.exists' => 'No existe una cita médica con ese cita_id.',
            'canal.required' => 'Indica el canal: correo o sms.',
            'canal.in' => 'El canal debe ser correo o sms.',
        ]);
    }

    /**
     * Si el cliente ya trae un correlation_id (por ejemplo, la web) se respeta;
     * si no trae o no es válido, se genera uno nuevo.
     */
    private function correlationId(Request $request): string
    {
        $recibido = (string) $request->header(self::CABECERA, '');

        return preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $recibido) === 1
            ? $recibido
            : 'cita-'.Str::uuid();
    }

    private function mensaje(array $datos, string $correlationId): MensajeNotificacionCita
    {
        $cita = Agenda::with('paciente:id,codigo_paciente')->findOrFail($datos['cita_id']);

        return new MensajeNotificacionCita(
            (string) Str::uuid(),
            $correlationId,
            CarbonImmutable::now(),
            [
                'cita_id' => $cita->id,
                'codigo_paciente' => $cita->paciente?->codigo_paciente,
                'fecha' => $cita->fecha?->format('Y-m-d'),
                'hora_inicio' => substr((string) $cita->hora_inicio, 0, 5),
                'canal' => $datos['canal'],
            ],
        );
    }

    private function registrar(MensajeNotificacionCita $mensaje, string $modo): NotificacionCita
    {
        return NotificacionCita::create([
            'message_id' => $mensaje->messageId,
            'correlation_id' => $mensaje->correlationId,
            'cita_id' => $mensaje->payload['cita_id'],
            'canal' => $mensaje->payload['canal'],
            'modo' => $modo,
            'estado' => NotificacionCita::PENDIENTE,
        ]);
    }
}
