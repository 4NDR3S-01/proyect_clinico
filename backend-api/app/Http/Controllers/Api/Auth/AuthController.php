<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterPacienteRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {
    }

    public function login(
        Request $request
    ): JsonResponse {
        $datos = $request->validate([
            'username' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
            ],
        ]);

        $resultado = $this
            ->authService
            ->login(
                $datos['username'],
                $datos['password']
            );

        $user = $resultado['user'];

        return response()->json([
            'message' => 'Inicio de sesión correcto.',
            'token_type' => 'Bearer',
            'access_token' => $resultado['token'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $resultado['user']->email,
                'role' => $user->role,
                'paciente_id' => $user->paciente_id,
                'activo' => $user->activo,
            ],
        ]);
    }

    public function logout(
        Request $request
    ): JsonResponse {
        $this
            ->authService
            ->logout(
                $request->user()
            );

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    public function registerPaciente(
        RegisterPacienteRequest $request
    ): JsonResponse {
        $resultado = $this
            ->authService
            ->registrarPaciente(
                $request->validated()
            );

        return response()->json([
            'message' => 'Cuenta de paciente creada correctamente.',
            'token_type' => 'Bearer',
            'access_token' => $resultado['token'],
            'user' => [
                'id' => $resultado['user']->id,
                'name' => $resultado['user']->name,
                'username' => $resultado['user']->username,
                'role' => $resultado['user']->role,
                'paciente_id' =>
                    $resultado['paciente']->id,
                'codigo_paciente' =>
                    $resultado['paciente']
                        ->codigo_paciente,
                'activo' =>
                    $resultado['user']->activo,
            ],
        ], 201);
    }
}