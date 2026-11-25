<?php

namespace App\Services\Enrutamiento;

use App\Mensajeria\Enrutamiento\Consumidores\ConsumidorCancelaciones;
use App\Mensajeria\Enrutamiento\Consumidores\ConsumidorConfirmaciones;
use App\Mensajeria\Enrutamiento\Consumidores\ConsumidorNotificacion;
use App\Mensajeria\Enrutamiento\Consumidores\ConsumidorRecordatorios;
use App\Mensajeria\Enrutamiento\Consumidores\RevisionNoEnrutables;
use App\Mensajeria\Enrutamiento\EnrutadorNotificaciones;
use App\Mensajeria\Enrutamiento\MensajeNotificacion;
use App\Models\Agenda;
use App\Models\MensajeEnrutado;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Práctica 3.2: lo que hace el proceso consumidor con cada mensaje de su cola,
 * separado de RabbitMQ para poder probarlo. Devuelve qué responder al broker:
 * ACK (atendido o repetido) o RECHAZAR (va a clinica.notificaciones.fallidas).
 *
 * Antes de atender comprueba que el mensaje de verdad le corresponde a este
 * consumidor según la tabla del enrutador: un mensaje mal enrutado se rechaza
 * en lugar de procesarse en el lugar equivocado.
 */
class EntregaAConsumidor
{
    public const ACK = 'ack';

    public const RECHAZAR = 'rechazar';

    public const CONSUMIDORES = [
        'confirmaciones' => ConsumidorConfirmaciones::class,
        'recordatorios' => ConsumidorRecordatorios::class,
        'cancelaciones' => ConsumidorCancelaciones::class,
        'no_enrutables' => RevisionNoEnrutables::class,
    ];

    public function __construct(
        private EnrutadorNotificaciones $enrutador,
    ) {
    }

    public static function consumidor(string $destino): ConsumidorNotificacion
    {
        $clase = self::CONSUMIDORES[$destino]
            ?? throw new InvalidArgumentException("No existe el destino {$destino}. Use: ".implode(', ', array_keys(self::CONSUMIDORES)).'.');

        return app($clase);
    }

    public function procesar(string $destino, string $cuerpo): string
    {
        $consumidor = self::consumidor($destino);

        try {
            $mensaje = MensajeNotificacion::desdeJson($cuerpo);
        } catch (ValidationException $e) {
            Log::channel('enrutamiento')->error('consumidor.mensaje_invalido', [
                'consumidor' => $consumidor->nombre(),
                'motivo' => implode(' ', $e->validator->errors()->all()),
            ]);

            return self::RECHAZAR;
        }

        $contexto = [
            'consumidor' => $consumidor->nombre(),
            'mensaje_id' => $mensaje->id,
            'correlation_id' => $mensaje->correlationId,
            'tipo' => $mensaje->tipo,
        ];

        $esperado = $this->enrutador->enrutar($mensaje)->nombre;

        if ($esperado !== $destino) {
            Log::channel('enrutamiento')->error('consumidor.mensaje_ajeno', $contexto + ['destino_correcto' => $esperado]);

            return self::RECHAZAR;
        }

        $registro = MensajeEnrutado::where('mensaje_id', $mensaje->id)
            ->where('destino', $destino)
            ->whereIn('decision', [MensajeEnrutado::ENRUTADO, MensajeEnrutado::NO_ENRUTABLE])
            ->latest('id')
            ->first();

        // RabbitMQ entrega "al menos una vez": si ya se atendió, no se avisa dos veces.
        if ($registro?->consumido_en !== null) {
            Log::channel('enrutamiento')->info('consumidor.duplicado_ignorado', $contexto);

            return self::ACK;
        }

        Log::channel('enrutamiento')->info('consumidor.mensaje_recibido', $contexto + ['prioridad' => $mensaje->prioridad]);

        try {
            $cita = Agenda::find($mensaje->citaId);
            $resultado = $consumidor->atender($mensaje, $cita);
        } catch (Throwable $e) {
            Log::channel('enrutamiento')->error('consumidor.error', $contexto + ['error' => $e->getMessage()]);

            return self::RECHAZAR;
        }

        $registro?->update([
            'consumido_por' => $consumidor->nombre(),
            'consumido_en' => now(),
            'resultado' => $resultado,
        ]);

        Log::channel('enrutamiento')->info('consumidor.mensaje_atendido', $contexto + ['resultado' => $resultado]);

        return self::ACK;
    }
}
