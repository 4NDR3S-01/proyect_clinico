<?php

namespace App\Policies;

use App\Models\Agenda;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Práctica 2.4 (William Cabrera): quién puede ver o cancelar una cita médica.
 *
 * El middleware auth:sanctum ya garantiza que hay un usuario autenticado; aquí
 * se decide si ese usuario puede trabajar con esta cita en concreto, según su
 * rol y si la cita es suya.
 */
class CitaMedicaPolicy
{
    public function ver(User $user, Agenda $cita): Response
    {
        if (in_array($user->role, ['doctora', 'asistente'], true)) {
            return Response::allow();
        }

        if ($this->esPropia($user, $cita)) {
            return Response::allow();
        }

        return Response::deny('No puedes consultar una cita médica que no es tuya.');
    }

    public function cancelar(User $user, Agenda $cita): Response
    {
        if ($user->role === 'doctora') {
            return Response::allow();
        }

        if ($this->esPropia($user, $cita)) {
            return Response::allow();
        }

        if ($user->role === 'asistente') {
            return Response::deny('El rol asistente solo puede consultar citas médicas.');
        }

        return Response::deny('No puedes cancelar una cita médica que no es tuya.');
    }

    private function esPropia(User $user, Agenda $cita): bool
    {
        return $user->role === 'paciente'
            && $user->paciente_id !== null
            && (string) $user->paciente_id === (string) $cita->paciente_id;
    }
}
