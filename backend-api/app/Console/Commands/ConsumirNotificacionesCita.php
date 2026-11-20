<?php

namespace App\Console\Commands;

use App\Mensajeria\ConexionRabbitMq;
use App\Services\Asincrono\ProcesadorMensajeNotificacion;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Práctica 3.1 (William Cabrera): consumidor independiente de la API.
 *
 *   php artisan citas:consumir-notificaciones
 *
 * Lee de clinica.citas.notificaciones de a un mensaje (prefetch 1) y confirma
 * con ack solo después de procesarlo. Si el proceso muere antes del ack, el
 * mensaje vuelve a la cola y lo toma el siguiente consumidor.
 */
class ConsumirNotificacionesCita extends Command
{
    protected $signature = 'citas:consumir-notificaciones
        {--max=0 : Termina después de procesar esta cantidad de mensajes (0 = sin límite)}';

    protected $description = 'Consume la cola de notificaciones de citas médicas (RabbitMQ).';

    private bool $detener = false;

    public function handle(ProcesadorMensajeNotificacion $procesador): int
    {
        $cola = config('rabbitmq.notificaciones_cita.cola');
        $max = (int) $this->option('max');
        $procesados = 0;

        $conexion = ConexionRabbitMq::abrir(heartbeat: 30);
        $canal = $conexion->channel();
        ConexionRabbitMq::declararTopologia($canal);
        $canal->basic_qos(0, 1, false);

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->detener = true);
            pcntl_signal(SIGTERM, fn () => $this->detener = true);
        }

        $this->info("Consumidor escuchando la cola {$cola} (pid ".getmypid().'). Ctrl+C para detener.');

        $canal->basic_consume($cola, '', false, false, false, false,
            function (AMQPMessage $msg) use ($procesador, &$procesados) {
                $correlationId = $msg->has('correlation_id') ? $msg->get('correlation_id') : null;
                $inicio = hrtime(true);

                $this->line(sprintf('[%s] recibido  correlation_id=%s message_id=%s',
                    now()->format('H:i:s.v'), $correlationId ?? '-',
                    $msg->has('message_id') ? $msg->get('message_id') : '-'));

                $resultado = $procesador->procesar($msg->getBody(), $correlationId);
                $ms = (int) round((hrtime(true) - $inicio) / 1e6);

                if ($resultado === ProcesadorMensajeNotificacion::ACK) {
                    $msg->ack();
                    $this->info(sprintf('[%s] procesado correlation_id=%s en %d ms -> ack',
                        now()->format('H:i:s.v'), $correlationId ?? '-', $ms));
                } else {
                    // requeue=false: el broker lo envía a la cola de fallidos.
                    $msg->reject(false);
                    $this->error(sprintf('[%s] rechazado correlation_id=%s -> cola de fallidos',
                        now()->format('H:i:s.v'), $correlationId ?? '-'));
                }

                $procesados++;
            });

        while ($canal->is_consuming() && ! $this->detener && ($max === 0 || $procesados < $max)) {
            try {
                $canal->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                // Sin mensajes en el último segundo: se vuelve a revisar si hay que detenerse.
            }
        }

        $canal->close();
        $conexion->close();
        $this->info("Consumidor detenido. Mensajes procesados: {$procesados}.");

        return self::SUCCESS;
    }
}
