<?php

namespace App\Mensajeria;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Abre la conexión con RabbitMQ y declara la topología de la práctica:
 *
 *   exchange clinica.citas (direct) --notificacion.cita_registrada--> cola clinica.citas.notificaciones
 *   cola clinica.citas.notificaciones --rechazados--> exchange clinica.citas.dlx --> cola ...fallidas
 *
 * Declarar es idempotente: productor y consumidor lo hacen al conectarse, así
 * cualquiera de los dos puede arrancar primero.
 */
class ConexionRabbitMq
{
    public static function abrir(float $heartbeat = 0): AMQPStreamConnection
    {
        $c = config('rabbitmq');

        return new AMQPStreamConnection(
            $c['host'],
            $c['port'],
            $c['user'],
            $c['password'],
            $c['vhost'],
            connection_timeout: $c['connection_timeout'],
            read_write_timeout: $heartbeat > 0 ? $heartbeat * 2 : 10,
            heartbeat: (int) $heartbeat,
        );
    }

    public static function declararTopologia(AMQPChannel $canal): void
    {
        $n = config('rabbitmq.notificaciones_cita');

        // Cola de mensajes rechazados.
        $canal->exchange_declare($n['exchange_fallidos'], 'fanout', false, true, false);
        $canal->queue_declare($n['cola_fallidos'], false, true, false, false);
        $canal->queue_bind($n['cola_fallidos'], $n['exchange_fallidos']);

        // Cola principal: durable, y lo que se rechace va al exchange de fallidos.
        $canal->exchange_declare($n['exchange'], 'direct', false, true, false);
        $canal->queue_declare($n['cola'], false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => $n['exchange_fallidos'],
        ]));
        $canal->queue_bind($n['cola'], $n['exchange'], $n['routing_key']);
    }
}
