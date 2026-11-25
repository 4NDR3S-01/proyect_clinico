<?php

namespace App\Mensajeria\Enrutamiento;

use App\Mensajeria\BrokerNoDisponibleException;

/**
 * Entrega el mensaje al destino que eligió el enrutador. La implementación
 * real es RabbitMqPublicadorEnrutado; en las pruebas se usa una falsa.
 */
interface PublicadorEnrutado
{
    /**
     * @throws BrokerNoDisponibleException si el broker no confirmó la entrega (fallo de transporte).
     */
    public function publicar(MensajeNotificacion $mensaje, Destino $destino): void;
}
