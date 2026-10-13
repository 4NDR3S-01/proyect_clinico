<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordService
{
    public function enviarEnlaceRecuperacion(
        string $email
    ): void {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => [
                    'No existe una cuenta registrada con este correo electrónico.',
                ],
            ]);
        }

        if (!$user->activo) {
            throw ValidationException::withMessages([
                'email' => [
                    'Esta cuenta se encuentra desactivada.',
                ],
            ]);
        }

        $estado = Password::sendResetLink([
            'email' => $email,
        ]);

        if ($estado !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [
                    'No se pudo enviar el enlace de recuperación.',
                ],
            ]);
        }
    }

    public function restablecerPassword(
        array $datos
    ): void {
        $estado = Password::reset(
            [
                'email' => $datos['email'],
                'password' => $datos['password'],
                'password_confirmation' =>
                    $datos['password_confirmation'],
                'token' => $datos['token'],
            ],
            function (User $user, string $password) {
                DB::transaction(function () use (
                    $user,
                    $password
                ) {
                    $user->update([
                        'password' => Hash::make($password),
                    ]);

                    $user->tokens()->delete();
                });
            }
        );

        if ($estado !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [
                    'El token de recuperación es inválido o ha expirado.',
                ],
            ]);
        }
    }
}