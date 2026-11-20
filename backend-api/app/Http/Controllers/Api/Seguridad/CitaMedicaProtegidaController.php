<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Agenda;
use App\Services\AgendaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Práctica 2.4 (William Cabrera): consulta y cancelación de una cita médica
 * con autenticación (auth:sanctum) y autorización por rol y propietario
 * (CitaMedicaPolicy).
 */
class CitaMedicaProtegidaController extends Controller
{
    public function __construct(
        private AgendaService $agendaService
    ) {
    }

    public function show(string $id): JsonResponse
    {
        $cita = $this->buscarCita($id);

        if (! $cita) {
            return $this->noEncontrada();
        }

        Gate::authorize('ver', $cita);

        return response()->json([
            'data' => $cita,
        ]);
    }

    public function cancelar(Request $request, string $id): JsonResponse
    {
        $cita = $this->buscarCita($id);

        if (! $cita) {
            return $this->noEncontrada();
        }

        Gate::authorize('cancelar', $cita);

        $user = $request->user();

        // La lógica de negocio sigue en AgendaService; aquí solo se controla el acceso.
        $resultado = $user->role === 'doctora'
            ? $this->agendaService->cancelarCita($cita->id, $user)
            : $this->agendaService->cancelarMiCita($cita->id, $user);

        return match ($resultado['estado']) {
            'cancelada' => response()->json([
                'message' => 'Cita cancelada correctamente.',
                'data' => $resultado['registro'],
            ]),
            'ya_cancelada' => response()->json([
                'message' => 'La cita ya se encuentra cancelada.',
            ], 409),
            'completada' => response()->json([
                'message' => 'Una cita completada no puede cancelarse.',
            ], 409),
            'ya_inicio' => response()->json([
                'message' => 'La cita ya empezó y no puede cancelarse.',
            ], 409),
            default => $this->noEncontrada(),
        };
    }

    private function buscarCita(string $id): ?Agenda
    {
        return Agenda::where('id', $id)
            ->where('tipo', 'cita_medica')
            ->first();
    }

    private function noEncontrada(): JsonResponse
    {
        return response()->json([
            'message' => 'Cita médica no encontrada.',
        ], 404);
    }
}
