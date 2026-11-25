<?php

namespace App\Mensajeria\Enrutamiento\Consumidores;

use App\Mensajeria\Enrutamiento\MensajeNotificacion;
use App\Models\Agenda;

/**
 * Un consumidor atiende solo los mensajes de su cola y devuelve un texto con
 * lo que hizo, que queda en la bitácora.
 */
interface ConsumidorNotificacion
{
    public function nombre(): string;

    public function atender(MensajeNotificacion $mensaje, ?Agenda $cita): string;
}
