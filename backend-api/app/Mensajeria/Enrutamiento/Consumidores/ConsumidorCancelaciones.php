<?php

namespace App\Mensajeria\Enrutamiento\Consumidores;

use App\Models\Agenda;

/**
 * Consumidor de la cola clinica.notificaciones.cancelaciones (regla R3):
 * avisa que la cita se canceló y cómo pedir una nueva.
 */
class ConsumidorCancelaciones extends ConsumidorConAvisoSimulado
{
    public function nombre(): string
    {
        return 'consumidor_cancelaciones';
    }

    protected function texto(Agenda $cita): string
    {
        return "Clínica odontológica: su cita del {$this->cuando($cita)} fue cancelada. Puede solicitar una nueva desde la app.";
    }
}
