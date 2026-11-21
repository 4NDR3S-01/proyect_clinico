<?php

namespace App\Http\Controllers\Api\Orquestacion;

use App\Http\Controllers\Controller;
use App\Services\Orquestacion\DependenciaFallidaException;
use App\Services\Orquestacion\OrquestadorResumenCita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Práctica 3.3 (William Cabrera): GET /api/resumen-cita/{id}
 *
 * Lo consume la web en Agenda > "Consultar resumen de cita" (aporte de Jereny Vera).
 * Respuestas:
 *   200 {"data": {"cita": {...}, "paciente": {...}}}  resumen completo
 *   200 {"data": null}                                 la cita no tiene paciente registrado
 *   401/403                                            token o permiso rechazado
 *   404                                                la cita o su paciente no existen
 *   502                                                un servicio respondió algo inválido
 *   503 + Retry-After                                  un servicio no responde
 */
class ResumenCitaController extends Controller
{
    public function __construct(
        private OrquestadorResumenCita $orquestador
    ) {
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $this->correlationId($request);
        $cabeceras = ['X-Correlation-ID' => $correlationId];

        try {
            $resumen = $this->orquestador->resumen($id, (string) $request->bearerToken(), $correlationId);
        } catch (DependenciaFallidaException $e) {
            Log::channel('orquestacion')->warning('orquestacion.respuesta_error', [
                'correlation_id' => $correlationId,
                'cita_id' => $id,
                'servicio' => $e->servicio,
                'status' => $e->status,
                'motivo' => $e->getMessage(),
            ]);

            if ($e->status === 503) {
                $cabeceras['Retry-After'] = '10';
            }

            return response()->json([
                'message' => $e->getMessage(),
                'servicio' => $e->servicio,
                'correlation_id' => $correlationId,
            ], $e->status, $cabeceras);
        }

        if ($resumen === null) {
            return response()->json([
                'data' => null,
                'message' => 'La cita no tiene un paciente registrado.',
                'correlation_id' => $correlationId,
            ], 200, $cabeceras);
        }

        return response()->json([
            'data' => $resumen,
            'correlation_id' => $correlationId,
        ], 200, $cabeceras);
    }

    private function correlationId(Request $request): string
    {
        $recibido = (string) $request->header('X-Correlation-ID', '');

        return preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $recibido) === 1
            ? $recibido
            : 'resumen-'.Str::uuid();
    }
}
