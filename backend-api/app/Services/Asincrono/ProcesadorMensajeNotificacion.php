<?php

namespace App\Services\Asincrono;

use App\Mensajeria\MensajeNotificacionCita;
use App\Models\NotificacionCita;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Práctica 3.1: lo que hace el consumidor con cada mensaje, separado de
 * RabbitMQ para poder probarlo. Devuelve qué responderle al broker:
 *
 * - ACK: el mensaje se procesó (o ya se había procesado antes) y se borra de la cola.
 * - RECHAZAR: el mensaje no sirve o el procesamiento falló; el broker lo pasa
 *   a la cola de fallidos (dead letter) y no se reintenta en bucle.
 */
class ProcesadorMensajeNotificacion
{
    public const ACK = 'ack';

    public const RECHAZAR = 'rechazar';

    public function __construct(
        private NotificadorCitaMedica $notificador
    ) {
    }

    public function procesar(string $cuerpo, ?string $correlationIdCabecera = null): string
    {
        try {
            $mensaje = MensajeNotificacionCita::desdeJson($cuerpo);
        } catch (InvalidArgumentException $e) {
            Log::channel('mensajeria')->error('consumidor.mensaje_invalido', [
                'correlation_id' => $correlationIdCabecera,
                'motivo' => $e->getMessage(),
            ]);

            return self::RECHAZAR;
        }

        $contexto = [
            'correlation_id' => $mensaje->correlationId,
            'message_id' => $mensaje->messageId,
        ];

        Log::channel('mensajeria')->info('consumidor.mensaje_recibido', $contexto);

        $registro = NotificacionCita::firstOrCreate(
            ['message_id' => $mensaje->messageId],
            [
                'correlation_id' => $mensaje->correlationId,
                'cita_id' => $mensaje->payload['cita_id'],
                'canal' => $mensaje->payload['canal'],
                'modo' => 'asincrono',
                'estado' => NotificacionCita::PENDIENTE,
            ]
        );

        // Entrega repetida (RabbitMQ garantiza "al menos una vez"): no se avisa dos veces.
        if (in_array($registro->estado, [NotificacionCita::ENVIADA, NotificacionCita::DESCARTADA], true)) {
            Log::channel('mensajeria')->info('consumidor.duplicado_ignorado', $contexto + ['estado' => $registro->estado]);

            return self::ACK;
        }

        try {
            $this->notificador->procesar($registro);
        } catch (Throwable $e) {
            $registro->update([
                'estado' => NotificacionCita::FALLIDA,
                'detalle' => mb_substr($e->getMessage(), 0, 250),
                'procesada_en' => now(),
            ]);

            Log::channel('mensajeria')->error('consumidor.error', $contexto + ['error' => $e->getMessage()]);

            return self::RECHAZAR;
        }

        return self::ACK;
    }
}
