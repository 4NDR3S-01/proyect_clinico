<?php

namespace App\Mensajeria\Enrutamiento;

use App\Models\Agenda;
use Illuminate\Support\Carbon;

/**
 * Práctica 3.2 (William Cabrera): Message Filter.
 *
 * Deja pasar solo las notificaciones que tiene sentido enviar. Un mensaje
 * filtrado es una decisión de negocio (no se le avisa al paciente), no un
 * fallo de transporte: no se publica en RabbitMQ y queda en la bitácora con
 * la regla que lo detuvo.
 *
 *   F1  La cita existe, es una cita médica y tiene paciente.
 *   F2  La cita no ha pasado (fecha y hora de inicio posteriores a ahora).
 *   F3  El estado de la cita coincide con el tipo del mensaje:
 *       confirmacion y recordatorio -> programada; cancelacion -> cancelada.
 *
 * F3 solo aplica a los tipos conocidos. Un tipo desconocido pasa el filtro y
 * es el enrutador quien lo manda a no_enrutables.
 */
class FiltroNotificacionCita
{
    public const ESTADO_REQUERIDO = [
        'confirmacion' => 'programada',
        'recordatorio' => 'programada',
        'cancelacion' => 'cancelada',
    ];

    public const REGLAS = [
        'F1' => 'La cita existe, es una cita médica y tiene paciente.',
        'F2' => 'La cita no ha pasado (fecha y hora de inicio posteriores a ahora).',
        'F3' => 'El estado de la cita coincide con el tipo: confirmacion/recordatorio -> programada, cancelacion -> cancelada.',
    ];

    public function evaluar(MensajeNotificacion $mensaje): ResultadoFiltro
    {
        $cita = Agenda::query()
            ->where('id', $mensaje->citaId)
            ->where('tipo', 'cita_medica')
            ->whereNotNull('paciente_id')
            ->first();

        if (! $cita) {
            return ResultadoFiltro::rechazar('F1', 'La cita no existe o no es una cita médica con paciente.');
        }

        $inicio = $this->inicio($cita);

        if ($inicio !== null && $inicio->lessThanOrEqualTo(Carbon::now())) {
            return ResultadoFiltro::rechazar('F2', sprintf(
                'La cita ya pasó (%s); no se notifica.', $inicio->format('Y-m-d H:i')
            ), $cita);
        }

        $requerido = self::ESTADO_REQUERIDO[$mensaje->tipo] ?? null;

        if ($requerido !== null && $cita->estado !== $requerido) {
            return ResultadoFiltro::rechazar('F3', sprintf(
                'Una notificación de %s requiere la cita en estado %s, pero está %s.',
                $mensaje->tipo, $requerido, $cita->estado ?? 'sin estado'
            ), $cita);
        }

        return ResultadoFiltro::aceptar($cita);
    }

    private function inicio(Agenda $cita): ?Carbon
    {
        if (! $cita->fecha) {
            return null;
        }

        $hora = $cita->hora_inicio ? substr((string) $cita->hora_inicio, 0, 5) : '00:00';

        return Carbon::parse($cita->fecha->format('Y-m-d').' '.$hora, config('app.timezone'));
    }
}
