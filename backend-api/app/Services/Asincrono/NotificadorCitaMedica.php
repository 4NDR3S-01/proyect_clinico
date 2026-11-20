<?php

namespace App\Services\Asincrono;

use App\Models\Agenda;
use App\Models\NotificacionCita;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Práctica 3.1: el trabajo que se saca de la petición HTTP. Arma el aviso para
 * el paciente, lo entrega al proveedor y deja el resultado en notificaciones_cita.
 *
 * Lo usan las dos versiones: la síncrona (dentro de la petición) y el consumidor
 * de la cola. El proveedor de correo/SMS es simulado: espera
 * NOTIFICACION_LATENCIA_MS milisegundos, como lo haría una API externa, y no
 * envía nada a nadie.
 */
class NotificadorCitaMedica
{
    public function procesar(NotificacionCita $registro): NotificacionCita
    {
        $inicio = hrtime(true);

        $registro->update([
            'estado' => NotificacionCita::PROCESANDO,
            'intentos' => $registro->intentos + 1,
        ]);

        $cita = Agenda::with('paciente')
            ->where('id', $registro->cita_id)
            ->where('tipo', 'cita_medica')
            ->first();

        // Si la cita se canceló mientras el mensaje esperaba en la cola, ya no se avisa.
        if (! $cita || ! $cita->paciente || $cita->estado === 'cancelada') {
            return $this->terminar($registro, NotificacionCita::DESCARTADA, $inicio, [
                'detalle' => 'La cita ya no existe o fue cancelada; no se envía el aviso.',
            ]);
        }

        $destino = $this->destino($cita, $registro->canal);

        if ($destino === null) {
            return $this->terminar($registro, NotificacionCita::DESCARTADA, $inicio, [
                'detalle' => "El paciente no tiene {$registro->canal} registrado.",
            ]);
        }

        $mensaje = sprintf(
            'Clínica odontológica: su cita del %s a las %s quedó registrada. Si no puede asistir, cancélela desde la app.',
            $cita->fecha->format('d/m/Y'),
            substr((string) $cita->hora_inicio, 0, 5),
        );

        $this->enviarAlProveedor($registro, $mensaje);

        return $this->terminar($registro, NotificacionCita::ENVIADA, $inicio, [
            'destino' => $this->enmascarar($destino, $registro->canal),
            'mensaje' => $mensaje,
            'detalle' => null,
        ]);
    }

    private function enviarAlProveedor(NotificacionCita $registro, string $mensaje): void
    {
        $latencia = max(0, (int) config('services.notificaciones.latencia_ms', 2000));
        usleep($latencia * 1000);

        Log::channel('mensajeria')->info('proveedor.envio_simulado', [
            'correlation_id' => $registro->correlation_id,
            'message_id' => $registro->message_id,
            'canal' => $registro->canal,
            'latencia_ms' => $latencia,
        ]);
    }

    private function terminar(NotificacionCita $registro, string $estado, int $inicio, array $datos): NotificacionCita
    {
        $registro->update($datos + [
            'estado' => $estado,
            'procesada_en' => now(),
        ]);

        Log::channel('mensajeria')->info('notificacion.'.$estado, [
            'correlation_id' => $registro->correlation_id,
            'message_id' => $registro->message_id,
            'modo' => $registro->modo,
            'duracion_ms' => (int) round((hrtime(true) - $inicio) / 1e6),
        ]);

        return $registro;
    }

    private function destino(Agenda $cita, string $canal): ?string
    {
        if ($canal === 'sms') {
            return $cita->paciente->telefono ?: null;
        }

        return User::where('paciente_id', $cita->paciente_id)->value('email') ?: null;
    }

    private function enmascarar(string $destino, string $canal): string
    {
        if ($canal === 'sms') {
            return substr($destino, 0, 2).str_repeat('*', max(0, strlen($destino) - 4)).substr($destino, -2);
        }

        [$usuario, $dominio] = array_pad(explode('@', $destino, 2), 2, '');

        return substr($usuario, 0, 1).'***@'.$dominio;
    }
}
