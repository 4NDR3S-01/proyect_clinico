<?php

namespace App\Mensajeria\Enrutamiento\Consumidores;

use App\Models\Agenda;

/**
 * Consumidor de la cola clinica.notificaciones.recordatorios (regla R2):
 * recuerda la cita próxima para reducir inasistencias.
 */
class ConsumidorRecordatorios extends ConsumidorConAvisoSimulado
{
    public function nombre(): string
    {
        return 'consumidor_recordatorios';
    }

    protected function texto(Agenda $cita): string
    {
        return "Clínica odontológica: le recordamos su cita del {$this->cuando($cita)}. Si no puede asistir, cancélela desde la app.";
    }
}
