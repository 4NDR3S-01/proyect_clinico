<?php

namespace App\Mensajeria;

/**
 * Lo que necesita el productor: dejar un mensaje en la cola. La implementación
 * real es RabbitMqPublicador; en las pruebas se usa una falsa.
 */
interface PublicadorMensajes
{
    /**
     * Publica el mensaje y espera la confirmación del broker.
     *
     * @throws BrokerNoDisponibleException si no se pudo publicar.
     */
    public function publicar(MensajeNotificacionCita $mensaje): void;
}
