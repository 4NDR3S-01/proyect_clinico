<?php

namespace App\Http\Controllers\Api\Normalizacion;

use App\Http\Controllers\Controller;
use App\Services\AgendaService;
use App\Services\Normalizacion\ContratoCitaMedica;
use App\Services\Normalizacion\NormalizadorCitaMedica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Adaptador HTTP de la Práctica 2.3 (William Cabrera). No contiene reglas de
 * traducción: delega en NormalizadorCitaMedica y reutiliza AgendaService con
 * el contrato común, igual que POST /api/agenda/cita-medica.
 */
class NormalizacionCitaMedicaController extends Controller
{
    public function __construct(
        private readonly NormalizadorCitaMedica $normalizador,
        private readonly AgendaService $agendaService,
    ) {}

    /** Traduce sin guardar: muestra el contrato común resultante. */
    public function normalizar(Request $request, string $origen): JsonResponse
    {
        return response()->json([
            'message' => 'Entrada normalizada al contrato común.',
            'origen' => $origen,
            'data' => $this->normalizador->normalizar($origen, $request->json()->all()),
        ]);
    }

    /** Traduce, valida el contrato y registra la cita con el servicio existente. */
    public function registrar(Request $request, string $origen): JsonResponse
    {
        $contrato = $this->normalizador->normalizar($origen, $request->json()->all());

        Validator::make($contrato, ContratoCitaMedica::reglas(), ContratoCitaMedica::mensajes())->validate();

        $resultado = $this->agendaService->crearCitaMedica($contrato, $request->user());

        if ($resultado['estado'] === 'horario_ocupado') {
            return response()->json([
                'message' => 'Ese horario ya se encuentra ocupado.',
            ], 409);
        }

        return response()->json([
            'message' => 'Cita médica registrada correctamente.',
            'origen' => $origen,
            'contrato' => $contrato,
            'data' => $resultado['registro'],
        ], 201);
    }
}
