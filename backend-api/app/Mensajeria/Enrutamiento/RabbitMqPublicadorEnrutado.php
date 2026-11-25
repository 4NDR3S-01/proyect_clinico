<?php

namespace App\Mensajeria\Enrutamiento;

use App\Mensajeria\BrokerNoDisponibleException;
use App\Mensajeria\ConexionRabbitMq;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Publica en el exchange clinica.notificaciones con la routing key del destino.
 * El mensaje es persistente, lleva la prioridad AMQP y se espera la
 * confirmación del broker (publisher confirms). Con mandatory=true, si ninguna
 * cola está enlazada a esa routing key el broker lo devuelve y se trata como
 * fallo de transporte, no como mensaje filtrado.
 */
class RabbitMqPublicadorEnrutado implements PublicadorEnrutado
{
    public function publicar(MensajeNotificacion $mensaje, Destino $destino): void
    {
        $fallo = null;

        try {
            $conexion = ConexionRabbitMq::abrir();
            $canal = $conexion->channel();
            TopologiaEnrutamiento::declarar($canal);
            $canal->confirm_select();

            $canal->set_nack_handler(function () use (&$fallo) {
                $fallo = 'RabbitMQ rechazó el mensaje (nack).';
            });
            $canal->set_return_listener(function () use (&$fallo) {
                $fallo = 'Ninguna cola recibió el mensaje (routing key sin enlace).';
            });

            $canal->basic_publish(new AMQPMessage($mensaje->aJson(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $mensaje->prioridadAmqp(),
                'message_id' => $mensaje->id,
                'correlation_id' => $mensaje->correlationId,
                'type' => $mensaje->tipo,
                'timestamp' => $mensaje->ocurridoEn->getTimestamp(),
                'app_id' => 'backend-api',
                'application_headers' => new AMQPTable([
                    'x-regla' => $destino->regla,
                    'x-destino' => $destino->nombre,
                ]),
            ]), config('rabbitmq.enrutamiento.exchange'), $destino->routingKey, true);

            $canal->wait_for_pending_acks_returns(5);

            $canal->close();
            $conexion->close();
        } catch (Throwable $e) {
            throw new BrokerNoDisponibleException('No se pudo publicar en RabbitMQ: '.$e->getMessage(), 0, $e);
        }

        if ($fallo !== null) {
            throw new BrokerNoDisponibleException($fallo);
        }
    }
}
