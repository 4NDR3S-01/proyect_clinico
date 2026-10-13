<?php

namespace App\Http\Controllers\Api\Configuracion;

use App\Http\Controllers\Controller;
use App\Services\AuditoriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function __construct(
        private AuditoriaService $auditoriaService
    ) {
    }

    public function acciones(
        Request $request
    ): JsonResponse {
        $acciones = $this
            ->auditoriaService
            ->listarAcciones(
                $request->only([
                    'fecha',
                ])
            );

        return response()->json([
            'acciones' => $acciones,
        ]);
    }

    public function sesiones(
        Request $request
    ): JsonResponse {
        $sesiones = $this
            ->auditoriaService
            ->listarSesiones(
                $request->only([
                    'fecha',
                ])
            );

        return response()->json([
            'sesiones' => $sesiones,
        ]);
    }

    public function ocultarAccion(
        string $id
    ): JsonResponse {
        $ocultada = $this
            ->auditoriaService
            ->ocultarAccion($id);

        if (!$ocultada) {
            return response()->json([
                'message' => 'Acción no encontrada.',
            ], 404);
        }

        return response()->json([
            'message' => 'Acción eliminada de la vista correctamente.',
        ]);
    }
}