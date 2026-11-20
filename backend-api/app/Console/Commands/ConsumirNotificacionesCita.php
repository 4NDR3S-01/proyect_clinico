<?php

namespace App\Console\Commands;

use App\Mensajeria\ConexionRabbitMq;
use App\Services\Asincrono\ProcesadorMensajeNotificacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
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

    private const ESPERA_RECONEXION = 5;

    private bool $detener = false;

    public function handle(ProcesadorMensajeNotificacion $procesador): int
    {
        $cola = config('rabbitmq.notificaciones_cita.cola');
        $max = (int) $this->option('max');
        $procesados = 0;

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->detener = true);
            pcntl_signal(SIGTERM, fn () => $this->detener = true);
        }

        $this->info("Consumidor escuchando la cola {$cola} (pid ".getmypid().'). Ctrl+C para detener.');

        // Si se pierde la conexión (broker reiniciado, red caída o la laptop se
        // suspendió y no llegaron los heartbeats), se vuelve a conectar. Los
        // mensajes que no alcanzaron a tener ack regresan solos a la cola.
        while (! $this->detener && ($max === 0 || $procesados < $max)) {
            try {
                $this->consumir($procesador, $cola, $max, $procesados);
            } catch (AMQPHeartbeatMissedException|AMQPConnectionClosedException|AMQPIOException|AMQPRuntimeException $e) {
                if ($this->detener) {
                    break;
                }

                Log::channel('mensajeria')->warning('consumidor.conexion_perdida', [
                    'error' => $e->getMessage(),
                ]);
                $this->warn(sprintf('[%s] se perdió la conexión con RabbitMQ (%s). Reintento en %d s.',
                    now()->format('H:i:s.v'), $e->getMessage(), self::ESPERA_RECONEXION));

                $this->esperar(self::ESPERA_RECONEXION);
            }
        }

        $this->info("Consumidor detenido. Mensajes procesados: {$procesados}.");

        return self::SUCCESS;
    }

    private function consumir(ProcesadorMensajeNotificacion $procesador, string $cola, int $max, int &$procesados): void
    {
        $conexion = ConexionRabbitMq::abrir(heartbeat: 30);

        try {
            $canal = $conexion->channel();
            ConexionRabbitMq::declararTopologia($canal);
            $canal->basic_qos(0, 1, false);

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
        } finally {
            // Si la conexión ya estaba rota, cerrarla no debe tapar el error original.
            try {
                $conexion->close();
            } catch (\Throwable) {
            }
        }
    }

    private function esperar(int $segundos): void
    {
        for ($i = 0; $i < $segundos * 10 && ! $this->detener; $i++) {
            usleep(100_000);
        }
    }
}
