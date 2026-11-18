<?php

namespace App\Services\Normalizacion;

/**
 * Contrato común de la cita médica: el mismo JSON que acepta
 * POST /api/agenda/cita-medica. Ambos orígenes se traducen a esta forma.
 * Práctica 2.3, William Cabrera.
 *
 * {"codigo_paciente":"007","fecha":"2026-11-20","hora_inicio":"09:00","hora_fin":"09:30","descripcion":"Limpieza dental"}
 */
final class ContratoCitaMedica
{
    public const CAMPOS = ['codigo_paciente', 'fecha', 'hora_inicio', 'hora_fin', 'descripcion'];

    /**
     * Validación del contrato ya normalizado (segunda barrera, contra la BD).
     *
     * @return array<string, list<string>>
     */
    public static function reglas(): array
    {
        return [
            'codigo_paciente' => ['required', 'string', 'exists:pacientes,codigo_paciente'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'descripcion' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public static function mensajes(): array
    {
        return [
            'codigo_paciente.exists' => 'No existe un paciente con ese código.',
        ];
    }
}
