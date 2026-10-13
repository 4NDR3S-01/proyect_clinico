<?php

namespace App\Services;

use App\Models\Actividad;
use App\Models\Egreso;
use Carbon\Carbon;

class ReporteService
{
    public function resumenCaja(array $datos): array
    {
        $ingresosQuery = Actividad::query();
        $egresosQuery = Egreso::query();

        if ($datos['periodo'] === 'dia') {
            $ingresosQuery->whereDate(
                'fecha',
                $datos['fecha']
            );

            $egresosQuery->whereDate(
                'fecha',
                $datos['fecha']
            );
        }

        if ($datos['periodo'] === 'semana') {
            $fecha = Carbon::parse($datos['fecha']);

            $inicio = $fecha->copy()->startOfWeek();
            $fin = $fecha->copy()->endOfWeek();

            $ingresosQuery->whereBetween(
                'fecha',
                [$inicio, $fin]
            );

            $egresosQuery->whereBetween(
                'fecha',
                [$inicio, $fin]
            );
        }

        if ($datos['periodo'] === 'mes') {
            $ingresosQuery
                ->whereMonth('fecha', $datos['mes'])
                ->whereYear('fecha', $datos['anio']);

            $egresosQuery
                ->whereMonth('fecha', $datos['mes'])
                ->whereYear('fecha', $datos['anio']);
        }

        if ($datos['periodo'] === 'anio') {
            $ingresosQuery->whereYear(
                'fecha',
                $datos['anio']
            );

            $egresosQuery->whereYear(
                'fecha',
                $datos['anio']
            );
        }

        $ingresos = (float) $ingresosQuery->sum('precio');
        $egresos = (float) $egresosQuery->sum('monto');
        $saldo = $ingresos - $egresos;

        return [
            'periodo' => $datos['periodo'],
            'ingresos' => number_format(
                $ingresos,
                2,
                '.',
                ''
            ),
            'egresos' => number_format(
                $egresos,
                2,
                '.',
                ''
            ),
            'saldo' => number_format(
                $saldo,
                2,
                '.',
                ''
            ),
        ];
    }
}
