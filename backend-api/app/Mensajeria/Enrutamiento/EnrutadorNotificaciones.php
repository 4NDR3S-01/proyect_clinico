<?php

namespace App\Mensajeria\Enrutamiento;

/**
 * Práctica 3.2 (William Cabrera): Content-Based Router.
 *
 * Mira el campo "tipo" del mensaje y elige uno de tres consumidores. El tipo
 * decide la cola; la prioridad decide el orden dentro de esa cola (prioridad
 * AMQP), así un aviso "alta" se atiende antes que uno "baja" que llegó antes.
 *
 * Un tipo que no está en la tabla no se descarta: va a no_enrutables (R0) para
 * revisión manual, con el motivo.
 */
class EnrutadorNotificaciones
{
    public const NO_ENRUTABLES = 'no_enrutables';

    /** tipo => [regla, destino, justificación] */
    public const REGLAS = [
        'confirmacion' => [
            'regla' => 'R1',
            'destino' => 'confirmaciones',
            'justificacion' => 'La doctora programó la cita: el paciente recibe la confirmación con fecha y hora.',
        ],
        'recordatorio' => [
            'regla' => 'R2',
            'destino' => 'recordatorios',
            'justificacion' => 'Cita próxima: se recuerda al paciente para reducir inasistencias.',
        ],
        'cancelacion' => [
            'regla' => 'R3',
            'destino' => 'cancelaciones',
            'justificacion' => 'La cita se canceló: se avisa al paciente y se le indica cómo pedir otra.',
        ],
    ];

    public function enrutar(MensajeNotificacion $mensaje): Destino
    {
        $regla = self::REGLAS[$mensaje->tipo] ?? null;

        if ($regla === null) {
            return $this->destino(self::NO_ENRUTABLES, 'R0', "Tipo no reconocido: {$mensaje->tipo}.");
        }

        return $this->destino($regla['destino'], $regla['regla']);
    }

    /** Tabla de reglas para la documentación y el endpoint GET /reglas. */
    public function tabla(): array
    {
        $filas = [];

        foreach (self::REGLAS as $tipo => $regla) {
            $filas[] = $this->fila($regla['regla'], "tipo = {$tipo}", $regla['destino'], $regla['justificacion']);
        }

        $filas[] = $this->fila('R0', 'cualquier otro tipo', self::NO_ENRUTABLES,
            'No se pierde: queda en una cola aparte para revisión manual.');

        return $filas;
    }

    private function fila(string $regla, string $condicion, string $destino, string $justificacion): array
    {
        $config = config("rabbitmq.enrutamiento.destinos.{$destino}");

        return [
            'regla' => $regla,
            'condicion' => $condicion,
            'destino' => $destino,
            'routing_key' => $config['routing_key'],
            'cola' => $config['cola'],
            'justificacion' => $justificacion,
        ];
    }

    private function destino(string $nombre, string $regla, ?string $motivo = null): Destino
    {
        $config = config("rabbitmq.enrutamiento.destinos.{$nombre}");

        return new Destino($nombre, $regla, $config['routing_key'], $config['cola'], $motivo);
    }
}
