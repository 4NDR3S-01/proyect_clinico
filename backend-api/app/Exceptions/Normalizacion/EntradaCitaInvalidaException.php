<?php

namespace App\Exceptions\Normalizacion;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Error explícito de traducción: la entrada de un origen no puede llevarse
 * al contrato común sin adivinar valores. Práctica 2.3, William Cabrera.
 */
class EntradaCitaInvalidaException extends InvalidArgumentException
{
    /**
     * @param  array<string, list<string>>  $errores  mensajes por campo del origen
     */
    public function __construct(
        public readonly string $origen,
        public readonly array $errores,
    ) {
        parent::__construct("La entrada del origen '{$origen}' no cumple las reglas de normalización.");
    }

    /** Laravel usa este método para responder 422 sin código extra en el controlador. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'origen' => $this->origen,
            'errors' => $this->errores,
        ], 422);
    }
}
