<?php

namespace App\Mensajeria;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Práctica 3.1 (William Cabrera): mensaje que viaja por la cola de notificaciones.
 *
 * - message_id identifica este mensaje (un envío concreto); sirve para no
 *   procesarlo dos veces si el broker lo entrega de nuevo.
 * - correlation_id identifica la operación completa (la petición del cliente,
 *   el mensaje, el consumidor y el registro final comparten este valor).
 *
 * El payload lleva solo lo necesario: nada de nombres, cédula, teléfono ni correo.
 */
final class MensajeNotificacionCita
{
    public const TIPO = 'notificacion_cita_registrada';

    public const CANALES = ['correo', 'sms'];

    public function __construct(
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly CarbonImmutable $ocurridoEn,
        public readonly array $payload,
    ) {
    }

    public function aArreglo(): array
    {
        return [
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'tipo' => self::TIPO,
            'ocurrido_en' => $this->ocurridoEn->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'payload' => $this->payload,
        ];
    }

    public function aJson(): string
    {
        return json_encode($this->aArreglo(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Reconstruye el mensaje en el consumidor. Si el cuerpo no cumple el
     * contrato, lanza InvalidArgumentException y el mensaje se rechaza.
     */
    public static function desdeJson(string $cuerpo): self
    {
        try {
            $datos = json_decode($cuerpo, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('El cuerpo del mensaje no es JSON válido.');
        }

        if (! is_array($datos)) {
            throw new InvalidArgumentException('El mensaje debe ser un objeto JSON.');
        }

        foreach (['message_id', 'correlation_id', 'tipo', 'ocurrido_en', 'payload'] as $campo) {
            if (! array_key_exists($campo, $datos)) {
                throw new InvalidArgumentException("Falta el campo {$campo}.");
            }
        }

        if ($datos['tipo'] !== self::TIPO) {
            throw new InvalidArgumentException("Tipo de mensaje no soportado: {$datos['tipo']}.");
        }

        $payload = $datos['payload'];

        if (! is_array($payload) || ! isset($payload['cita_id'], $payload['canal'])) {
            throw new InvalidArgumentException('El payload debe incluir cita_id y canal.');
        }

        if (! in_array($payload['canal'], self::CANALES, true)) {
            throw new InvalidArgumentException("Canal no soportado: {$payload['canal']}.");
        }

        try {
            $ocurrido = CarbonImmutable::parse($datos['ocurrido_en']);
        } catch (\Throwable) {
            throw new InvalidArgumentException('ocurrido_en no es una fecha válida.');
        }

        return new self(
            (string) $datos['message_id'],
            (string) $datos['correlation_id'],
            $ocurrido,
            $payload,
        );
    }
}
