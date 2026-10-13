<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UsuarioService
{
    public function listar(string $buscar = ''): LengthAwarePaginator
    {
        $buscar = trim($buscar);
        $patron = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($buscar)).'%';

        return User::query()
            ->select([
                'id',
                'name',
                'email',
                'username',
                'role',
                'paciente_id',
                'activo',
                'created_at',
            ])
            ->when($buscar !== '', function ($query) use ($patron) {
                $query->where(function ($query) use ($patron) {
                    $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$patron])
                        ->orWhereRaw("LOWER(username) LIKE ? ESCAPE '!'", [$patron])
                        ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$patron]);
                });
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(20)
            ->appends($buscar !== '' ? ['buscar' => $buscar] : []);
    }

    public function obtenerPorId(
        string $id
    ): ?User {
        return User::find($id);
    }

    public function listarAsistentes()
    {
        return User::where(
            'role',
            'asistente'
        )
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function obtenerAsistentePorId(
        string $id
    ): ?User {
        return User::where(
            'role',
            'asistente'
        )
            ->where('id', $id)
            ->first();
    }

    public function crearAsistente(
        array $datos,
        User $user
    ): array {
        return DB::transaction(function () use ($datos, $user) {
            $asistente = User::create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'username' => $datos['username'],
                'password' => $datos['password'],
                'role' => 'asistente',
                'paciente_id' => null,
                'activo' => true,
            ]);

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'crear_asistente',
                "Creaste la cuenta del asistente {$asistente->name}."
            );

            return [
                'estado' => 'creado',
                'asistente' => $asistente,
            ];
        });
    }

    public function desactivarAsistente(
        string $id,
        User $user
    ): array {
        return DB::transaction(function () use ($id, $user) {
            $asistente = $this->obtenerAsistentePorId($id);

            if (!$asistente) {
                return [
                    'estado' => 'no_existe',
                    'asistente' => null,
                ];
            }

            if (!$asistente->activo) {
                return [
                    'estado' => 'ya_desactivado',
                    'asistente' => $asistente,
                ];
            }

            $asistente->update([
                'activo' => false,
            ]);

            $asistente->tokens()->delete();

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'desactivar_asistente',
                "Desactivaste la cuenta del asistente {$asistente->name}."
            );

            return [
                'estado' => 'desactivado',
                'asistente' => $asistente->fresh(),
            ];
        });
    }

    public function activarAsistente(
        string $id,
        User $user
    ): array {
        return DB::transaction(function () use ($id, $user) {
            $asistente = $this->obtenerAsistentePorId($id);

            if (!$asistente) {
                return [
                    'estado' => 'no_existe',
                    'asistente' => null,
                ];
            }

            if ($asistente->activo) {
                return [
                    'estado' => 'ya_activo',
                    'asistente' => $asistente,
                ];
            }

            $asistente->update([
                'activo' => true,
            ]);

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'activar_asistente',
                "Activaste la cuenta del asistente {$asistente->name}."
            );

            return [
                'estado' => 'activado',
                'asistente' => $asistente->fresh(),
            ];
        });
    }

    public function cambiarPasswordAsistente(
        string $id,
        string $password,
        User $user
    ): string {
        return DB::transaction(function () use (
            $id,
            $password,
            $user
        ) {
            $asistente = $this->obtenerAsistentePorId($id);

            if (!$asistente) {
                return 'no_existe';
            }

            $asistente->update([
                'password' => $password,
            ]);

            $asistente->tokens()->delete();

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'cambiar_password_asistente',
                "Cambiaste la contraseña del asistente {$asistente->name}."
            );

            return 'actualizado';
        });
    }

    public function eliminarAsistente(
        string $id,
        User $user
    ): string {
        return DB::transaction(function () use ($id, $user) {
            $asistente = $this->obtenerAsistentePorId($id);

            if (!$asistente) {
                return 'no_existe';
            }

            $nombreAsistente = $asistente->name;

            $asistente->tokens()->delete();

            $asistente->delete();

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'eliminar_asistente',
                "Eliminaste la cuenta del asistente {$nombreAsistente}."
            );

            return 'eliminado';
        });
    }

    public function desactivarUsuario(
        string $id,
        User $user
    ): array {
        return DB::transaction(function () use ($id, $user) {
            $usuario = User::find($id);

            if (!$usuario) {
                return [
                    'estado' => 'no_encontrado',
                    'usuario' => null,
                ];
            }

            if ($usuario->id === $user->id) {
                return [
                    'estado' => 'propia_cuenta',
                    'usuario' => $usuario,
                ];
            }

            if (!$usuario->activo) {
                return [
                    'estado' => 'ya_desactivado',
                    'usuario' => $usuario,
                ];
            }

            $usuario->update([
                'activo' => false,
            ]);

            $usuario->tokens()->delete();

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'desactivar_usuario',
                "Desactivaste la cuenta de {$usuario->name}."
            );

            return [
                'estado' => 'desactivado',
                'usuario' => $usuario->fresh(),
            ];
        });
    }

    public function activarUsuario(
        string $id,
        User $user
    ): array {
        return DB::transaction(function () use ($id, $user) {
            $usuario = User::find($id);

            if (!$usuario) {
                return [
                    'estado' => 'no_encontrado',
                    'usuario' => null,
                ];
            }

            if ($usuario->activo) {
                return [
                    'estado' => 'ya_activo',
                    'usuario' => $usuario,
                ];
            }

            if (
                $usuario->role === 'paciente' &&
                !$usuario->paciente_id
            ) {
                return [
                    'estado' => 'paciente_sin_vinculo',
                    'usuario' => $usuario,
                ];
            }

            $usuario->update([
                'activo' => true,
            ]);

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'activar_usuario',
                "Activaste la cuenta de {$usuario->name}."
            );

            return [
                'estado' => 'activado',
                'usuario' => $usuario->fresh(),
            ];
        });
    }

    public function desvincularPaciente(
        string $id,
        User $user
    ): array {
        return DB::transaction(function () use ($id, $user) {
            $usuario = User::find($id);

            if (!$usuario) {
                return [
                    'estado' => 'no_encontrado',
                    'usuario' => null,
                ];
            }

            if ($usuario->role !== 'paciente') {
                return [
                    'estado' => 'no_es_paciente',
                    'usuario' => $usuario,
                ];
            }

            if (!$usuario->paciente_id) {
                return [
                    'estado' => 'ya_desvinculado',
                    'usuario' => $usuario,
                ];
            }

            $paciente = Paciente::find(
                $usuario->paciente_id
            );

            $codigoPaciente = $paciente
                ? $paciente->codigo_paciente
                : null;

            $usuario->tokens()->delete();

            $usuario->update([
                'paciente_id' => null,
                'activo' => false,
            ]);

            $detalle = $codigoPaciente
                ? "Desvinculaste la cuenta del paciente {$codigoPaciente}."
                : "Desvinculaste la cuenta de {$usuario->name}.";

            AuditoriaService::registrarAccion(
                $user,
                'usuarios',
                'desvincular_paciente',
                $detalle
            );

            return [
                'estado' => 'desvinculado',
                'usuario' => $usuario->fresh(),
            ];
        });
    }
}
