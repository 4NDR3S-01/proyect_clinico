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

    /*
    | Práctica 3.2 (William Cabrera): enrutamiento de notificaciones de citas.
    | El enrutador elige el destino por el tipo del mensaje y publica en un
    | exchange "direct" con la routing key de ese destino. Cada destino tiene
    | su propia cola y su propio consumidor. Lo que el consumidor rechaza
    | (mensaje dañado o que no le corresponde) va a la cola de fallidos.
    */
    'enrutamiento' => [
        'exchange' => 'clinica.notificaciones',
        'exchange_fallidos' => 'clinica.notificaciones.dlx',
        'cola_fallidos' => 'clinica.notificaciones.fallidas',
        // La prioridad del mensaje decide el orden dentro de cada cola (0..10).
        'prioridad_maxima' => 10,

        'destinos' => [
            'confirmaciones' => [
                'routing_key' => 'notificacion.confirmacion',
                'cola' => 'clinica.notificaciones.confirmaciones',
            ],
            'recordatorios' => [
                'routing_key' => 'notificacion.recordatorio',
                'cola' => 'clinica.notificaciones.recordatorios',
            ],
            'cancelaciones' => [
                'routing_key' => 'notificacion.cancelacion',
                'cola' => 'clinica.notificaciones.cancelaciones',
            ],
            'no_enrutables' => [
                'routing_key' => 'notificacion.no_enrutable',
                'cola' => 'clinica.notificaciones.no_enrutables',
            ],
        ],
    ],
];
