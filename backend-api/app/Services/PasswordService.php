<?php

namespace App\Services;

use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordService
{
    public function enviarCodigoRecuperacion(string $email): void
    {
        // Never silently accept simulated delivery in production.
        if (app()->environment('production')) {
            $mailer = config('mail.default');
            abort_unless(
                in_array(config("mail.mailers.$mailer.transport"), ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail'], true),
                503,
                'El servicio de correo no está disponible temporalmente.'
            );
        }

        try {
            DB::transaction(function () use ($email): void {
                // Issuance and redemption serialize on the same user row.
                $user = User::where('email', $email)->lockForUpdate()->first();
                if (! $user || ! $user->activo) {
                    return;
                }

                $previous = PasswordResetCode::where('email', $user->email)->latest('id')->first();
                if ($previous && $previous->created_at->gt(now()->subMinute())) {
                    return;
                }

                $codigo = (string) random_int(100000, 999999);
                PasswordResetCode::where('email', $user->email)->delete();
                PasswordResetCode::create([
                    'email' => $user->email,
                    'code_hash' => Hash::make($codigo),
                    'expires_at' => now()->addMinutes(5),
                ]);

                // A transport failure rolls back issuance, preserving the previous code.
                $sent = Mail::raw(
                    "Tu código de recuperación es: {$codigo}\n\n"
                    ."Este código expira en 5 minutos. No lo compartas con nadie.\n\n"
                    .'Si no solicitaste este cambio, ignora este correo.',
                    function ($message) use ($user): void {
                        $message->to($user->email)->subject('Código de recuperación de contraseña');
                    }
                );
                if ($sent === null) {
                    throw new TransportException('Delivery cancelled');
                }
            });
        } catch (TransportExceptionInterface $e) {
            // Never log codes, addresses, credentials or SMTP exception contents.
            Log::error('password_reset_mail_failed', ['exception_type' => $e::class]);
            // Same public response for existing, missing and inactive accounts.
        }
    }

    public function restablecerPassword(array $datos): void
    {
        $changed = DB::transaction(function () use ($datos): bool {
            $user = User::where('email', $datos['email'])->lockForUpdate()->first();
            if (! $user || ! $user->activo) {
                return false;
            }

            $registro = PasswordResetCode::where('email', $user->email)
                ->latest('id')->lockForUpdate()->first();
            if (! $registro || $registro->used_at !== null
                || $registro->expires_at->lte(now()) || $registro->attempts >= 5) {
                return false;
            }

            if (! Hash::check($datos['code'], $registro->code_hash)) {
                $registro->increment('attempts');

                return false;
            }

            $user->forceFill([
                'password' => $datos['password'],
                'remember_token' => Str::random(60),
            ])->save();
            $registro->update(['used_at' => now()]);
            $user->tokens()->delete();

            return true;
        });

        // Throw after commit so an incorrect attempt is not rolled back.
        if (! $changed) {
            throw ValidationException::withMessages([
                'code' => ['El código no es válido o ha expirado. Solicita uno nuevo.'],
            ]);
        }
    }
}
