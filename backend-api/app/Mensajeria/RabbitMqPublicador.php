<?php

namespace App\Mensajeria;

use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Productor: publica el mensaje en RabbitMQ como persistente y espera la
 * confirmación del broker (publisher confirms) antes de responder al cliente.
 */
class RabbitMqPublicador implements PublicadorMensajes
{
    public function publicar(MensajeNotificacionCita $mensaje): void
    {
        $n = config('rabbitmq.notificaciones_cita');

        try {
            $conexion = ConexionRabbitMq::abrir();
            $canal = $conexion->channel();
            ConexionRabbitMq::declararTopologia($canal);
            $canal->confirm_select();

            $nack = false;
            $canal->set_nack_handler(function () use (&$nack) {
                $nack = true;
            });
            // mandatory=true: si ninguna cola recibe el mensaje, el broker lo devuelve.
            $canal->set_return_listener(function () use (&$nack) {
                $nack = true;
            });

            $canal->basic_publish(new AMQPMessage($mensaje->aJson(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $mensaje->messageId,
                'correlation_id' => $mensaje->correlationId,
                'type' => MensajeNotificacionCita::TIPO,
                'timestamp' => $mensaje->ocurridoEn->getTimestamp(),
                'app_id' => 'backend-api',
            ]), $n['exchange'], $n['routing_key'], true);

            $canal->wait_for_pending_acks_returns(5);

            $canal->close();
            $conexion->close();
        } catch (Throwable $e) {
            throw new BrokerNoDisponibleException('No se pudo publicar en RabbitMQ: '.$e->getMessage(), 0, $e);
        }

        if ($nack) {
            throw new BrokerNoDisponibleException('RabbitMQ rechazó el mensaje (nack).');
        }
    }
}
