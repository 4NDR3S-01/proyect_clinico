<?php

namespace App\Services\Orquestacion;

use RuntimeException;

/**
 * Un servicio del que depende el orquestador no respondió o respondió mal.
 * $status es el código HTTP que el orquestador devuelve al cliente.
 */
class DependenciaFallidaException extends RuntimeException
{
    public function __construct(
        public readonly string $servicio,
        public readonly int $status,
        string $motivo,
    ) {
        parent::__construct($motivo);
    }
}
