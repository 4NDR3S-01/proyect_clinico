<?php

/*
| Práctica 3.3 (William Cabrera): servicios que coordina el orquestador del
| resumen de cita. En el despliegue apuntan al balanceador NGINX (Laptop 1),
| que reparte entre backend1 y backend2; en pruebas pueden ser instancias aparte.
*/

return [
    'citas_url' => rtrim((string) env('ORQUESTACION_CITAS_URL', 'http://127.0.0.1:8000/api'), '/'),
    'pacientes_url' => rtrim((string) env('ORQUESTACION_PACIENTES_URL', 'http://127.0.0.1:8000/api'), '/'),

    // Segundos máximos para conectar y para esperar la respuesta de cada servicio.
    'connect_timeout' => (float) env('ORQUESTACION_CONNECT_TIMEOUT', 2),
    'timeout' => (float) env('ORQUESTACION_TIMEOUT', 3),
];
