<?php

namespace App\Console\Commands;

use App\Mensajeria\ConexionRabbitMq;
use App\Mensajeria\Enrutamiento\TopologiaEnrutamiento;
use App\Services\Enrutamiento\EntregaAConsumidor;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Práctica 3.2 (William Cabrera): un proceso consumidor por destino.
 *
 *   php artisan notificaciones:consumir confirmaciones
 *   php artisan notificaciones:consumir recordatorios
 *   php artisan notificaciones:consumir cancelaciones
 *   php artisan notificaciones:consumir no_enrutables
 *
 * Cada uno escucha solo su cola. Toma un mensaje a la vez (prefetch 1) y
 * confirma con ack después de atenderlo; lo que rechaza va a la cola de fallidos.
 */
class ConsumirNotificacionesEnrutadas extends Command
{
    protected $signature = 'notificaciones:consumir
        {destino : confirmaciones | recordatorios | cancelaciones | no_enrutables}
        {--max=0 : Termina después de esta cantidad de mensajes (0 = sin límite)}';

    protected $description = 'Consume la cola de un destino del enrutador de notificaciones (RabbitMQ).';

    private bool $detener = false;

    public function handle(EntregaAConsumidor $entrega): int
    {
        $destino = (string) $this->argument('destino');

        if (! array_key_exists($destino, EntregaAConsumidor::CONSUMIDORES)) {
            $this->error("Destino desconocido: {$destino}. Use: ".implode(', ', array_keys(EntregaAConsumidor::CONSUMIDORES)).'.');

            return self::INVALID;
        }

        $cola = config("rabbitmq.enrutamiento.destinos.{$destino}.cola");
        $nombre = EntregaAConsumidor::consumidor($destino)->nombre();
        $max = (int) $this->option('max');
        $procesados = 0;

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->detener = true);
            pcntl_signal(SIGTERM, fn () => $this->detener = true);
        }

        $conexion = ConexionRabbitMq::abrir(heartbeat: 30);
        $canal = $conexion->channel();
        TopologiaEnrutamiento::declarar($canal);
        $canal->basic_qos(0, 1, false);

        $this->info("{$nombre} escuchando la cola {$cola} (pid ".getmypid().'). Ctrl+C para detener.');

        $canal->basic_consume($cola, '', false, false, false, false,
            function (AMQPMessage $msg) use ($entrega, $destino, &$procesados) {
                $id = $msg->has('message_id') ? $msg->get('message_id') : '-';
                $prioridad = $msg->has('priority') ? $msg->get('priority') : '-';
                $tipo = $msg->has('type') ? $msg->get('type') : '-';

                $this->line(sprintf('[%s] recibido  id=%s tipo=%s prioridad_amqp=%s',
                    now()->format('H:i:s.v'), $id, $tipo, $prioridad));

                if ($entrega->procesar($destino, $msg->getBody()) === EntregaAConsumidor::ACK) {
                    $msg->ack();
                    $this->info(sprintf('[%s] atendido  id=%s -> ack', now()->format('H:i:s.v'), $id));
                } else {
                    $msg->reject(false);
                    $this->error(sprintf('[%s] rechazado id=%s -> clinica.notificaciones.fallidas', now()->format('H:i:s.v'), $id));
                }

                $procesados++;
            });

        while ($canal->is_consuming() && ! $this->detener && ($max === 0 || $procesados < $max)) {
            try {
                $canal->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                // Sin mensajes en el último segundo: se revisa si hay que detenerse.
            }
        }

        $canal->close();
        $conexion->close();
        $this->info("{$nombre} detenido. Mensajes procesados: {$procesados}.");

        return self::SUCCESS;
    }
}
