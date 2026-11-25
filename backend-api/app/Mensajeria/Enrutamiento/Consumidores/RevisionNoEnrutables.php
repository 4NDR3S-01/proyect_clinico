<?php

namespace App\Mensajeria\Enrutamiento\Consumidores;

use App\Mensajeria\Enrutamiento\MensajeNotificacion;
use App\Models\Agenda;
use Illuminate\Support\Facades\Log;

/**
 * Consumidor de la cola clinica.notificaciones.no_enrutables (regla R0).
 * No avisa a nadie: deja el mensaje registrado como pendiente de revisión
 * manual, para decidir si hace falta una regla nueva o si el productor envió
 * un tipo equivocado.
 */
class RevisionNoEnrutables implements ConsumidorNotificacion
{
    public function nombre(): string
    {
        return 'revision_no_enrutables';
    }

    public function atender(MensajeNotificacion $mensaje, ?Agenda $cita): string
    {
        Log::channel('enrutamiento')->warning('consumidor.pendiente_revision', [
            'consumidor' => $this->nombre(),
            'mensaje_id' => $mensaje->id,
            'correlation_id' => $mensaje->correlationId,
            'tipo' => $mensaje->tipo,
        ]);

        return "Pendiente de revisión manual: no hay consumidor para el tipo '{$mensaje->tipo}'. No se envió ningún aviso.";
    }
}
