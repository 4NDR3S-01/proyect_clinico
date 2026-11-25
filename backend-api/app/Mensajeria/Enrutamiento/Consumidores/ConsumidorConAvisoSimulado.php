<?php

namespace App\Mensajeria\Enrutamiento\Consumidores;

use App\Mensajeria\Enrutamiento\MensajeNotificacion;
use App\Models\Agenda;
use Illuminate\Support\Facades\Log;

/**
 * Base de los tres consumidores que avisan al paciente. El proveedor de
 * correo/SMS es simulado (igual que en la 3.1): espera
 * NOTIFICACION_LATENCIA_MS y escribe el envío en storage/logs/enrutamiento.log.
 * No se envía nada a nadie; la entrega por la cola sí es real.
 */
abstract class ConsumidorConAvisoSimulado implements ConsumidorNotificacion
{
    abstract protected function texto(Agenda $cita): string;

    public function atender(MensajeNotificacion $mensaje, ?Agenda $cita): string
    {
        // La cita pudo borrarse mientras el mensaje esperaba en la cola.
        if ($cita === null) {
            return 'La cita ya no existe; no se envió el aviso.';
        }

        $texto = $this->texto($cita);

        usleep(max(0, (int) config('services.notificaciones.latencia_ms', 2000)) * 1000);

        Log::channel('enrutamiento')->info('consumidor.aviso_enviado', [
            'consumidor' => $this->nombre(),
            'mensaje_id' => $mensaje->id,
            'correlation_id' => $mensaje->correlationId,
            'canal' => $mensaje->canal,
            'prioridad' => $mensaje->prioridad,
            'texto' => $texto,
        ]);

        return "[{$mensaje->canal}] {$texto}";
    }

    protected function cuando(Agenda $cita): string
    {
        return sprintf('%s a las %s', $cita->fecha?->format('d/m/Y') ?? 'fecha por definir',
            $cita->hora_inicio ? substr((string) $cita->hora_inicio, 0, 5) : '--:--');
    }
}
