<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    public function __construct(
        private PasswordService $passwordService
    ) {
    }

    public function forgotPassword(
        Request $request
    ): JsonResponse {
        $datos = $request->validate([
            'email' => [
                'required',
                'email',
            ],
        ]);

        $this
            ->passwordService
            ->enviarEnlaceRecuperacion(
                $datos['email']
            );

        return response()->json([
            'message' => 'Se envió el enlace de recuperación al correo electrónico.',
        ]);
    }

    public function resetPassword(
        Request $request
    ): JsonResponse {
        $datos = $request->validate([
            'email' => [
                'required',
                'email',
            ],
            'token' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $this
            ->passwordService
            ->restablecerPassword($datos);

        return response()->json([
            'message' => 'Contraseña restablecida correctamente.',
        ]);
    }
}
