<?php

namespace App\Services;

use App\Models\AccionRegistrada;
use App\Models\SesionRegistrada;
use App\Models\User;

class AuditoriaService
{
    public static function registrarAccion(
        User $user,
        string $modulo,
        string $accion,
        string $detalle
    ): void {
        AccionRegistrada::create([
            'user_id' => $user->id,
            'usuario' => $user->name,
            'rol' => $user->role,
            'modulo' => $modulo,
            'accion' => $accion,
            'detalle' => $detalle,
            'visible' => true,
        ]);
    }

    public static function registrarSesion(
        User $user,
        string $accion
    ): void {
        SesionRegistrada::create([
            'user_id' => $user->id,
            'usuario' => $user->name,
            'rol' => $user->role,
            'accion' => $accion,
        ]);
    }

    public function listarAcciones(
        array $filtros
    ) {
        $query = AccionRegistrada::query()
            ->where('visible', true);

        if (!empty($filtros['fecha'])) {
            $query->whereDate(
                'created_at',
                $filtros['fecha']
            );
        }

        return $query
            ->orderByDesc('created_at')
            ->get();
    }

    public function listarSesiones(
        array $filtros
    ) {
        $query = SesionRegistrada::query();

        if (!empty($filtros['fecha'])) {
            $query->whereDate(
                'created_at',
                $filtros['fecha']
            );
        }

        return $query
            ->orderByDesc('created_at')
            ->get();
    }

    public function ocultarAccion(
        string $id
    ): bool {
        $accion = AccionRegistrada::find($id);

        if (!$accion) {
            return false;
        }

        $accion->update([
            'visible' => false,
        ]);

        return true;
    }
}