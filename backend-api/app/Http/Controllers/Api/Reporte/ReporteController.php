<?php

namespace App\Http\Controllers\Api\Reporte;

use App\Http\Controllers\Controller;
use App\Services\ReporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function __construct(
        private ReporteService $reporteService
    ) {
    }

    public function clinicos(): JsonResponse
    {
        return response()->json([
            'message' => 'Reporte clínico',
        ]);
    }

    public function economicos(): JsonResponse
    {
        return response()->json([
            'message' => 'Reporte económico',
        ]);
    }

    public function financieros(): JsonResponse
    {
        return response()->json([
            'message' => 'Reporte financiero',
        ]);
    }

    public function ingresos(): JsonResponse
    {
        return response()->json([
            'message' => 'Estadísticas de ingresos',
        ]);
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'message' => 'Datos del dashboard',
        ]);
    }

    public function resumenCaja(
        Request $request
    ): JsonResponse {
        $datos = $request->validate([
            'periodo' => [
                'required',
                'in:dia,semana,mes,anio',
            ],
            'fecha' => [
                'required_if:periodo,dia,semana',
                'nullable',
                'date',
            ],
            'mes' => [
                'required_if:periodo,mes',
                'nullable',
                'integer',
                'between:1,12',
            ],
            'anio' => [
                'required_if:periodo,mes,anio',
                'nullable',
                'integer',
            ],
        ]);

        $resultado = $this
            ->reporteService
            ->resumenCaja($datos);

        return response()->json(
            $resultado
        );
    }
}
