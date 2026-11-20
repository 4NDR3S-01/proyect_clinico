<?php

/*
| Práctica 3.1 (William Cabrera): conexión con RabbitMQ y nombres de la cola de
| notificaciones de citas médicas. Las credenciales se leen del .env.
*/

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'vhost' => env('RABBITMQ_VHOST', 'clinica'),
    'user' => env('RABBITMQ_USER', ''),
    'password' => env('RABBITMQ_PASSWORD', ''),
    'connection_timeout' => (float) env('RABBITMQ_CONNECTION_TIMEOUT', 3),

    'notificaciones_cita' => [
        // El productor publica en este exchange con esta routing key.
        'exchange' => 'clinica.citas',
        'routing_key' => 'notificacion.cita_registrada',
        'cola' => 'clinica.citas.notificaciones',

        // Los mensajes que el consumidor rechaza terminan aquí (dead letter).
        'exchange_fallidos' => 'clinica.citas.dlx',
        'cola_fallidos' => 'clinica.citas.notificaciones.fallidas',
    ],
];
