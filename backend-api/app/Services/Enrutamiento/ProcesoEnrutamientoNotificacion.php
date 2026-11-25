<?php

namespace App\Services\Enrutamiento;

use App\Mensajeria\BrokerNoDisponibleException;
use App\Mensajeria\Enrutamiento\EnrutadorNotificaciones;
use App\Mensajeria\Enrutamiento\FiltroNotificacionCita;
use App\Mensajeria\Enrutamiento\MensajeNotificacion;
use App\Mensajeria\Enrutamiento\PublicadorEnrutado;
use App\Models\MensajeEnrutado;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Práctica 3.2 (William Cabrera): recorrido de un mensaje de notificación.
 *
 *   productor -> 1. validación -> 2. Message Filter -> 3. Content-Based Router
 *             -> 4. RabbitMQ (exchange direct + routing key) -> consumidor | no_enrutables
 *
 * Cada paso deja su decisión en la bitácora (mensajes_enrutados) y en
 * storage/logs/enrutamiento.log, siempre con el correlation_id.
 */
class ProcesoEnrutamientoNotificacion
{
    public function __construct(
        private FiltroNotificacionCita $filtro,
        private EnrutadorNotificaciones $enrutador,
        private PublicadorEnrutado $publicador,
    ) {
    }

    /**
     * @throws ValidationException si el mensaje no cumple el contrato (queda registrado como invalido).
     * @throws MensajeDuplicadoException si ese id ya fue aceptado antes.
     */
    public function procesar(array $entrada, string $correlationId): MensajeEnrutado
    {
        // 1. Validación: sin los campos indispensables no se enruta.
        try {
            $mensaje = MensajeNotificacion::desdeArreglo($entrada, $correlationId);
        } catch (ValidationException $e) {
            $this->registrar([
                'mensaje_id' => is_string($entrada['id'] ?? null) ? mb_substr($entrada['id'], 0, 64) : null,
                'correlation_id' => $correlationId,
                'tipo' => is_string($entrada['tipo'] ?? null) ? mb_substr($entrada['tipo'], 0, 40) : null,
                'decision' => MensajeEnrutado::INVALIDO,
                'regla' => 'V',
                'motivo' => mb_substr(implode(' ', $e->validator->errors()->all()), 0, 250),
            ]);

            throw $e;
        }

        $anterior = MensajeEnrutado::where('mensaje_id', $mensaje->id)
            ->whereIn('decision', MensajeEnrutado::DEFINITIVAS)
            ->first();

        if ($anterior) {
            throw new MensajeDuplicadoException($anterior);
        }

        $base = [
            'mensaje_id' => $mensaje->id,
            'correlation_id' => $mensaje->correlationId,
            'tipo' => $mensaje->tipo,
            'prioridad' => $mensaje->prioridad,
            'cita_id' => $mensaje->citaId,
            'canal' => $mensaje->canal,
        ];

        // 2. Message Filter: lo que no cumple la regla de negocio no llega a ningún consumidor.
        $filtro = $this->filtro->evaluar($mensaje);

        if (! $filtro->aceptado) {
            return $this->registrar($base + [
                'decision' => MensajeEnrutado::FILTRADO,
                'regla' => $filtro->regla,
                'motivo' => $filtro->motivo,
            ]);
        }

        // 3. Content-Based Router: el tipo decide el destino.
        $destino = $this->enrutador->enrutar($mensaje);

        $ruta = [
            'regla' => $destino->regla,
            'motivo' => $destino->motivo,
            'destino' => $destino->nombre,
            'routing_key' => $destino->routingKey,
            'cola' => $destino->cola,
        ];

        // 4. Entrega real por RabbitMQ. Si el broker falla es un error de transporte, no un filtrado.
        try {
            $this->publicador->publicar($mensaje, $destino);
        } catch (BrokerNoDisponibleException $e) {
            return $this->registrar($base + $ruta + [
                'decision' => MensajeEnrutado::ERROR_TRANSPORTE,
                'motivo' => mb_substr($e->getMessage(), 0, 250),
            ]);
        }

        return $this->registrar($base + $ruta + [
            'decision' => $destino->esNoEnrutable() ? MensajeEnrutado::NO_ENRUTABLE : MensajeEnrutado::ENRUTADO,
        ]);
    }

    private function registrar(array $datos): MensajeEnrutado
    {
        $registro = MensajeEnrutado::create($datos);

        $nivel = match ($registro->decision) {
            MensajeEnrutado::ERROR_TRANSPORTE => 'error',
            MensajeEnrutado::ENRUTADO => 'info',
            default => 'warning',
        };

        Log::channel('enrutamiento')->{$nivel}('enrutador.'.$registro->decision, array_filter([
            'correlation_id' => $registro->correlation_id,
            'mensaje_id' => $registro->mensaje_id,
            'tipo' => $registro->tipo,
            'prioridad' => $registro->prioridad,
            'regla' => $registro->regla,
            'destino' => $registro->destino,
            'routing_key' => $registro->routing_key,
            'motivo' => $registro->motivo,
        ], fn ($v) => $v !== null));

        return $registro;
    }
}
