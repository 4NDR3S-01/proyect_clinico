<?php

namespace App\Services;

use App\Models\EvidenciaClinica;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenciaClinicaService
{
    public function listar(array $filtros)
    {
        $query = EvidenciaClinica::query()
            ->with(['paciente', 'subidoPor'])
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
            ->with('subidoPor')
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

    /**
     * Sube el archivo al servidor FTP y registra la evidencia. Si falla el
     * registro en la base de datos, el archivo subido se elimina.
     */
    public function crear(
        array $datos,
        UploadedFile $archivo,
        User $user
    ): EvidenciaClinica {
        $nombreArchivo = Str::uuid().'.'.$archivo->extension();

        $ruta = "clinica/pacientes/{$datos['paciente_id']}/evidencias/{$nombreArchivo}";

        Storage::disk('ftp')->put(
            $ruta,
            fopen($archivo->getRealPath(), 'r')
        );

        try {
            return DB::transaction(function () use ($datos, $archivo, $ruta, $user) {
                $evidencia = EvidenciaClinica::create([
                    'paciente_id' => $datos['paciente_id'],
                    'tipo' => $datos['tipo'],
                    'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), -255),
                    'ruta_archivo' => $ruta,
                    'mime_type' => $archivo->getMimeType(),
                    'tamano' => $archivo->getSize(),
                    'descripcion' => $datos['descripcion'] ?? null,
                    'fecha' => $datos['fecha'] ?? now()->toDateString(),
                    'subido_por_id' => $user->id,
                ]);

                $paciente = Paciente::findOrFail(
                    $evidencia->paciente_id
                );

                AuditoriaService::registrarAccion(
                    $user,
                    'evidencias_clinicas',
                    'crear',
                    $user->role === 'paciente'
                        ? "Subiste {$this->tipoTexto($evidencia->tipo)} a tu expediente."
                        : "Registraste {$this->tipoTexto($evidencia->tipo)} para el paciente {$paciente->codigo_paciente}."
                );

                return $evidencia->load('subidoPor');
            });
        } catch (\Throwable $e) {
            try {
                Storage::disk('ftp')->delete($ruta);
            } catch (\Throwable $limpieza) {
                Log::warning('No se pudo eliminar el archivo huérfano del FTP', [
                    'ruta' => $ruta,
                    'error' => $limpieza->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    public function descargar(
        EvidenciaClinica $evidencia
    ): StreamedResponse {
        $disco = Storage::disk('ftp');

        abort_unless($disco->exists($evidencia->ruta_archivo), 404);

        return $disco->response(
            $evidencia->ruta_archivo,
            $evidencia->nombre_archivo,
            [
                'Content-Type' => $evidencia->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline'
        );
    }

    public function listarDelPaciente(
        User $user
    ) {
        return EvidenciaClinica::where('paciente_id', $user->paciente_id)
            ->with('subidoPor')
            ->orderBy('fecha', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function obtenerDelPaciente(
        string $id,
        User $user
    ): ?EvidenciaClinica {
        return EvidenciaClinica::where('id', $id)
            ->where('paciente_id', $user->paciente_id)
            ->first();
    }

    private function tipoTexto(
        string $tipo
    ): string {
        return match ($tipo) {
            'rx' => 'una radiografía',
            'documento' => 'un documento',
            default => 'un caso clínico',
        };
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

            $tipoTexto = $this->tipoTexto($evidencia->tipo);

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

            $tipoTexto = $this->tipoTexto($evidencia->tipo);

            $codigoPaciente = $paciente->codigo_paciente;

            $evidencia->delete();

            // Si el FTP no responde se lanza una excepción y se revierte el borrado.
            if ($evidencia->ruta_archivo && Storage::disk('ftp')->exists($evidencia->ruta_archivo)) {
                Storage::disk('ftp')->delete($evidencia->ruta_archivo);
            }

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
