<?php

namespace App\Services\Enrutamiento;

use App\Models\MensajeEnrutado;
use RuntimeException;

/**
 * El mismo id de mensaje ya se procesó: no se vuelve a enrutar ni a avisar.
 */
class MensajeDuplicadoException extends RuntimeException
{
    public function __construct(public readonly MensajeEnrutado $anterior)
    {
        parent::__construct("El mensaje {$anterior->mensaje_id} ya fue procesado.");
    }
}
