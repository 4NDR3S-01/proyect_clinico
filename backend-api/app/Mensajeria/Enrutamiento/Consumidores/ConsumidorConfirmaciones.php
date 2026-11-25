<?php

namespace App\Mensajeria\Enrutamiento\Consumidores;

use App\Models\Agenda;

/**
 * Consumidor de la cola clinica.notificaciones.confirmaciones (regla R1):
 * confirma al paciente la fecha y hora que programó la doctora.
 */
class ConsumidorConfirmaciones extends ConsumidorConAvisoSimulado
{
    public function nombre(): string
    {
        return 'consumidor_confirmaciones';
    }

    protected function texto(Agenda $cita): string
    {
        return "Clínica odontológica: su cita del {$this->cuando($cita)} quedó confirmada.";
    }
}
