<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PacienteService
{
    public function listar(): Collection
    {
        return Paciente::orderBy('created_at', 'desc')->get();
    }

    public function obtenerPorId(string $id): Paciente
    {
        return Paciente::findOrFail($id);
    }

    public function crear(
        array $datos,
        User $user
    ): Paciente {
        return DB::transaction(function () use ($datos, $user) {
            $numero = DB::scalar(
                "SELECT nextval('codigo_paciente_seq')"
            );

            $codigoPaciente = str_pad(
                (string) $numero,
                3,
                '0',
                STR_PAD_LEFT
            );

            $paciente = Paciente::create([
                'codigo_paciente' => $codigoPaciente,
                'nombres' => $datos['nombres'],
                'cedula' => $datos['cedula'],
                'telefono' => $datos['telefono'] ?? null,
                'direccion' => $datos['direccion'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
            ]);

            $detalle = $user->role === 'doctora'
                ? "Registraste al paciente {$paciente->codigo_paciente}."
                : "Asistente registró al paciente {$paciente->codigo_paciente}.";

            AuditoriaService::registrarAccion(
                $user,
                'pacientes',
                'crear',
                $detalle
            );

            return $paciente;
        });
    }

    public function actualizar(
        string $id,
        array $datos,
        User $user
    ): Paciente {
        return DB::transaction(function () use ($id, $datos, $user) {
            $paciente = Paciente::findOrFail($id);

            $paciente->update($datos);

            $paciente = $paciente->fresh();

            $detalle = $user->role === 'doctora'
                ? "Actualizaste los datos del paciente {$paciente->codigo_paciente}."
                : "Asistente actualizó los datos del paciente {$paciente->codigo_paciente}.";

            AuditoriaService::registrarAccion(
                $user,
                'pacientes',
                'actualizar',
                $detalle
            );

            return $paciente;
        });
    }

    public function obtenerPerfil(User $user): ?Paciente
    {
        if (!$user->paciente_id) {
            return null;
        }

        return Paciente::find($user->paciente_id);
    }
}
