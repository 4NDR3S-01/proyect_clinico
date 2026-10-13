<?php

namespace App\Http\Controllers\Api\Usuario;

use App\Http\Controllers\Controller;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function __construct(
        private UsuarioService $usuarioService
    ) {
    }

    public function index(): JsonResponse
    {
        $usuarios = $this
            ->usuarioService
            ->listar();

        return response()->json([
            'data' => $usuarios,
        ]);
    }

    public function show(
        string $id
    ): JsonResponse {
        $usuario = $this
            ->usuarioService
            ->obtenerPorId($id);

        if (!$usuario) {
            return response()->json([
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'username' => $usuario->username,
                'role' => $usuario->role,
                'paciente_id' => $usuario->paciente_id,
                'activo' => $usuario->activo,
                'created_at' => $usuario->created_at,
            ],
        ]);
    }

    public function listarAsistentes(): JsonResponse
    {
        $asistentes = $this
            ->usuarioService
            ->listarAsistentes();

        return response()->json([
            'data' => $asistentes,
        ]);
    }

    public function verAsistente(
        string $id
    ): JsonResponse {
        $asistente = $this
            ->usuarioService
            ->obtenerAsistentePorId($id);

        if (!$asistente) {
            return response()->json([
                'message' => 'Asistente no encontrado.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $asistente->id,
                'name' => $asistente->name,
                'email' => $asistente->email,
                'username' => $asistente->username,
                'role' => $asistente->role,
                'activo' => $asistente->activo,
            ],
        ]);
    }

    public function crearAsistente(
        Request $request
    ): JsonResponse {
        $datos = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
            ],
        ]);

        $resultado = $this
            ->usuarioService
            ->crearAsistente(
                $datos,
                $request->user()
            );

        $asistente = $resultado['asistente'];

        return response()->json([
            'message' => 'Cuenta de asistente creada correctamente.',
            'data' => [
                'id' => $asistente->id,
                'name' => $asistente->name,
                'email' => $asistente->email,
                'username' => $asistente->username,
                'role' => $asistente->role,
                'activo' => $asistente->activo,
            ],
        ], 201);
    }

    public function desactivarAsistente(
        Request $request,
        string $id
    ): JsonResponse {
        $resultado = $this
            ->usuarioService
            ->desactivarAsistente(
                $id,
                $request->user()
            );

        if ($resultado['estado'] === 'no_existe') {
            return response()->json([
                'message' => 'Asistente no encontrado.',
            ], 404);
        }

        if ($resultado['estado'] === 'ya_desactivado') {
            return response()->json([
                'message' => 'La cuenta de asistente ya está desactivada.',
            ], 409);
        }

        $asistente = $resultado['asistente'];

        return response()->json([
            'message' => 'Cuenta de asistente desactivada correctamente.',
            'data' => [
                'id' => $asistente->id,
                'name' => $asistente->name,
                'username' => $asistente->username,
                'role' => $asistente->role,
                'activo' => $asistente->activo,
            ],
        ]);
    }

    public function activarAsistente(
        Request $request,
        string $id
    ): JsonResponse {
        $resultado = $this
            ->usuarioService
            ->activarAsistente(
                $id,
                $request->user()
            );

        if ($resultado['estado'] === 'no_existe') {
            return response()->json([
                'message' => 'Asistente no encontrado.',
            ], 404);
        }

        if ($resultado['estado'] === 'ya_activo') {
            return response()->json([
                'message' => 'La cuenta de asistente ya está activa.',
            ], 409);
        }

        $asistente = $resultado['asistente'];

        return response()->json([
            'message' => 'Cuenta de asistente activada correctamente.',
            'data' => [
                'id' => $asistente->id,
                'name' => $asistente->name,
                'username' => $asistente->username,
                'role' => $asistente->role,
                'activo' => $asistente->activo,
            ],
        ]);
    }

    public function cambiarPasswordAsistente(
        Request $request,
        string $id
    ): JsonResponse {
        $datos = $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $resultado = $this
            ->usuarioService
            ->cambiarPasswordAsistente(
                $id,
                $datos['password'],
                $request->user()
            );

        if ($resultado === 'no_existe') {
            return response()->json([
                'message' => 'Asistente no encontrado.',
            ], 404);
        }

        return response()->json([
            'message' => 'Contraseña del asistente actualizada correctamente.',
        ]);
    }

    public function eliminarAsistente(
        Request $request,
        string $id
    ): JsonResponse {
        $resultado = $this
            ->usuarioService
            ->eliminarAsistente(
                $id,
                $request->user()
            );

        if ($resultado === 'no_existe') {
            return response()->json([
                'message' => 'Asistente no encontrado.',
            ], 404);
        }

        return response()->json([
            'message' => 'Cuenta de asistente eliminada correctamente.',
        ]);
    }

    public function desactivarUsuario(
        Request $request,
        string $id
    ): JsonResponse {
        $resultado = $this
            ->usuarioService
            ->desactivarUsuario(
                $id,
                $request->user()
            );

        if ($resultado['estado'] === 'no_encontrado') {
            return response()->json([
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        if ($resultado['estado'] === 'propia_cuenta') {
            return response()->json([
                'message' => 'No puedes desactivar tu propia cuenta.',
            ], 409);
        }

        if ($resultado['estado'] === 'ya_desactivado') {
            return response()->json([
                'message' => 'La cuenta ya se encuentra desactivada.',
            ], 409);
        }

        $usuario = $resultado['usuario'];

        return response()->json([
            'message' => 'Cuenta desactivada correctamente.',
            'data' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'username' => $usuario->username,
                'role' => $usuario->role,
                'paciente_id' => $usuario->paciente_id,
                'activo' => $usuario->activo,
            ],
        ]);
    }

    public function activarUsuario(
        Request $request,
        string $id
    ): JsonResponse {
        $resultado = $this
            ->usuarioService
            ->activarUsuario(
                $id,
                $request->user()
            );

        if ($resultado['estado'] === 'no_encontrado') {
            return response()->json([
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        if ($resultado['estado'] === 'ya_activo') {
            return response()->json([
                'message' => 'La cuenta ya se encuentra activa.',
            ], 409);
        }

        if ($resultado['estado'] === 'paciente_sin_vinculo') {
            return response()->json([
                'message' => 'La cuenta de paciente debe estar vinculada a un expediente antes de activarse.',
            ], 409);
        }

        $usuario = $resultado['usuario'];

        return response()->json([
            'message' => 'Cuenta activada correctamente.',
            'data' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'username' => $usuario->username,
                'role' => $usuario->role,
                'paciente_id' => $usuario->paciente_id,
                'activo' => $usuario->activo,
            ],
        ]);
    }

    public function desvincularPaciente(
        Request $request,
        string $id
    ): JsonResponse {
        $resultado = $this
            ->usuarioService
            ->desvincularPaciente(
                $id,
                $request->user()
            );

        if ($resultado['estado'] === 'no_encontrado') {
            return response()->json([
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        if ($resultado['estado'] === 'no_es_paciente') {
            return response()->json([
                'message' => 'Solo se pueden desvincular cuentas de pacientes.',
            ], 409);
        }

        if ($resultado['estado'] === 'ya_desvinculado') {
            return response()->json([
                'message' => 'La cuenta ya se encuentra desvinculada.',
            ], 409);
        }

        $usuario = $resultado['usuario'];

        return response()->json([
            'message' => 'Cuenta de paciente desvinculada correctamente.',
            'data' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'username' => $usuario->username,
                'role' => $usuario->role,
                'paciente_id' => $usuario->paciente_id,
                'activo' => $usuario->activo,
            ],
        ]);
    }
}