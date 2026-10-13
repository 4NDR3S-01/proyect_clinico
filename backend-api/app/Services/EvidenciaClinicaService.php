<?php

namespace App\Services;

use App\Models\EvidenciaClinica;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EvidenciaClinicaService
{
    public function listar(array $filtros)
    {
        $query = EvidenciaClinica::query()
            ->with('paciente')
            ->orderBy('fecha', 'desc');

        if (!empty($filtros['paciente_id'])) {
            $query->where(
                'paciente_id',
                $filtros['paciente_id']
            );
        }

        if (!empty($filtros['tipo'])) {
            $query->where(
                'tipo',
                $filtros['tipo']
            );
        }

        return $query->get();
    }

    public function obtenerPorPaciente(
        string $pacienteId
    ): array {
        $paciente = Paciente::find($pacienteId);

        if (!$paciente) {
            return [
                'paciente' => null,
                'evidencias' => [],
            ];
        }

        $evidencias = EvidenciaClinica::where(
            'paciente_id',
            $pacienteId
        )
            ->orderBy('fecha', 'desc')
            ->get();

        return [
            'paciente' => $paciente,
            'evidencias' => $evidencias,
        ];
    }

    public function obtenerPorId(
        string $id
    ): ?EvidenciaClinica {
        return EvidenciaClinica::with('paciente')
            ->find($id);
    }

    public function crear(
        array $datos,
        User $user
    ): EvidenciaClinica {
        return DB::transaction(function () use ($datos, $user) {
            $evidencia = EvidenciaClinica::create([
                'paciente_id' => $datos['paciente_id'],
                'tipo' => $datos['tipo'],
                'nombre_archivo' => $datos['nombre_archivo'],
                'ruta_archivo' => $datos['ruta_archivo'],
                'mime_type' => $datos['mime_type'],
                'tamano' => $datos['tamano'],
                'descripcion' => $datos['descripcion'] ?? null,
                'fecha' => $datos['fecha'],
            ]);

            $paciente = Paciente::findOrFail(
                $evidencia->paciente_id
            );

            $tipoTexto = $evidencia->tipo === 'rx'
                ? 'una radiografía'
                : 'un caso clínico';

            AuditoriaService::registrarAccion(
                $user,
                'evidencias_clinicas',
                'crear',
                "Registraste {$tipoTexto} para el paciente {$paciente->codigo_paciente}."
            );

            return $evidencia;
        });
    }

    public function actualizar(
        string $id,
        array $datos,
        User $user
    ): ?EvidenciaClinica {
        return DB::transaction(function () use ($id, $datos, $user) {
            $evidencia = EvidenciaClinica::find($id);

            if (!$evidencia) {
                return null;
            }

            $evidencia->update($datos);

            $paciente = Paciente::findOrFail(
                $evidencia->paciente_id
            );

            $tipoTexto = $evidencia->tipo === 'rx'
                ? 'una radiografía'
                : 'un caso clínico';

            AuditoriaService::registrarAccion(
                $user,
                'evidencias_clinicas',
                'actualizar',
                "Actualizaste {$tipoTexto} del paciente {$paciente->codigo_paciente}."
            );

            return $evidencia->fresh();
        });
    }

    public function eliminar(
        string $id,
        User $user
    ): bool {
        return DB::transaction(function () use ($id, $user) {
            $evidencia = EvidenciaClinica::find($id);

            if (!$evidencia) {
                return false;
            }

            $paciente = Paciente::findOrFail(
                $evidencia->paciente_id
            );

            $tipoTexto = $evidencia->tipo === 'rx'
                ? 'una radiografía'
                : 'un caso clínico';

            $codigoPaciente = $paciente->codigo_paciente;

            $evidencia->delete();

            AuditoriaService::registrarAccion(
                $user,
                'evidencias_clinicas',
                'eliminar',
                "Eliminaste {$tipoTexto} del paciente {$codigoPaciente}."
            );

            return true;
        });
    }
}
