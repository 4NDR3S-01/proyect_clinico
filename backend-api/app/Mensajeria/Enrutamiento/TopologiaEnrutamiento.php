<?php

namespace App\Mensajeria\Enrutamiento;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Práctica 3.2: topología real en RabbitMQ (vhost clinica).
 *
 *   exchange clinica.notificaciones (direct)
 *     notificacion.confirmacion  -> clinica.notificaciones.confirmaciones
 *     notificacion.recordatorio  -> clinica.notificaciones.recordatorios
 *     notificacion.cancelacion   -> clinica.notificaciones.cancelaciones
 *     notificacion.no_enrutable  -> clinica.notificaciones.no_enrutables
 *
 *   rechazados por un consumidor -> clinica.notificaciones.dlx (fanout) -> clinica.notificaciones.fallidas
 *
 * Declarar es idempotente: lo hacen el productor y cada consumidor al conectarse.
 */
class TopologiaEnrutamiento
{
    public static function declarar(AMQPChannel $canal): void
    {
        $c = config('rabbitmq.enrutamiento');

        $canal->exchange_declare($c['exchange_fallidos'], 'fanout', false, true, false);
        $canal->queue_declare($c['cola_fallidos'], false, true, false, false);
        $canal->queue_bind($c['cola_fallidos'], $c['exchange_fallidos']);

        $canal->exchange_declare($c['exchange'], 'direct', false, true, false);

        foreach ($c['destinos'] as $destino) {
            $canal->queue_declare($destino['cola'], false, true, false, false, false, new AMQPTable([
                'x-max-priority' => $c['prioridad_maxima'],
                'x-dead-letter-exchange' => $c['exchange_fallidos'],
            ]));
            $canal->queue_bind($destino['cola'], $c['exchange'], $destino['routing_key']);
        }
    }
}
