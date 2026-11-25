<?php

namespace App\Mensajeria\Enrutamiento;

/**
 * Destino elegido por el enrutador: el consumidor lógico y cómo se llega a él
 * en RabbitMQ (routing key del exchange y cola enlazada).
 */
final class Destino
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $regla,
        public readonly string $routingKey,
        public readonly string $cola,
        public readonly ?string $motivo = null,
    ) {
    }

    public function esNoEnrutable(): bool
    {
        return $this->nombre === EnrutadorNotificaciones::NO_ENRUTABLES;
    }
}
