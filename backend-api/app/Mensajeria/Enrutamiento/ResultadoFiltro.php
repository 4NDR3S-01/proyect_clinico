<?php

namespace App\Mensajeria\Enrutamiento;

use App\Models\Agenda;

/**
 * Lo que decide el Message Filter: si el mensaje sigue hacia el enrutador o se
 * queda aquí, con la regla que lo detuvo y el motivo.
 */
final class ResultadoFiltro
{
    private function __construct(
        public readonly bool $aceptado,
        public readonly ?string $regla,
        public readonly ?string $motivo,
        public readonly ?Agenda $cita,
    ) {
    }

    public static function aceptar(Agenda $cita): self
    {
        return new self(true, null, null, $cita);
    }

    public static function rechazar(string $regla, string $motivo, ?Agenda $cita = null): self
    {
        return new self(false, $regla, $motivo, $cita);
    }
}
