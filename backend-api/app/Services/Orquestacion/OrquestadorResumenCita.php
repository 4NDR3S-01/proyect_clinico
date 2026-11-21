<?php

namespace App\Services\Orquestacion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Práctica 3.3 (William Cabrera): orquestador del resumen de cita.
 *
 * Coordina dos servicios por HTTP, en este orden:
 *   1. Servicio de citas:     GET {citas}/agenda/{id}         -> la cita y su paciente_id
 *   2. Servicio de pacientes: GET {pacientes}/pacientes/{pid} -> código y nombres
 *
 * El segundo paso depende del primero (necesita el paciente_id), por eso no
 * van en paralelo. Reenvía el token del usuario y el X-Correlation-ID, y solo
 * devuelve el resumen si los dos servicios respondieron bien.
 */
class OrquestadorResumenCita
{
    /**
     * @return array{cita: array, paciente: array}|null  null si la cita no tiene paciente registrado.
     *
     * @throws DependenciaFallidaException
     */
    public function resumen(string $citaId, string $token, string $correlationId): ?array
    {
        $inicio = hrtime(true);

        $cita = $this->consultar('citas', config('orquestacion.citas_url').'/agenda/'.rawurlencode($citaId), $token, $correlationId);

        if (! is_array($cita) || ($cita['id'] ?? null) !== $citaId || ($cita['tipo'] ?? null) !== 'cita_medica') {
            if (is_array($cita) && ($cita['id'] ?? null) === $citaId) {
                // Existe, pero es un registro personal de la agenda, no una cita médica.
                throw new DependenciaFallidaException('citas', 404, 'El registro no es una cita médica.');
            }

            throw new DependenciaFallidaException('citas', 502, 'El servicio de citas devolvió una respuesta inválida.');
        }

        // Reserva hecha a nombre de alguien que todavía no es paciente: no hay a quién consultar.
        if (empty($cita['paciente_id'])) {
            $this->registrar('orquestacion.sin_paciente', $correlationId, $citaId, $inicio);

            return null;
        }

        $paciente = $this->consultar('pacientes', config('orquestacion.pacientes_url').'/pacientes/'.rawurlencode($cita['paciente_id']), $token, $correlationId);

        if (! is_array($paciente) || ($paciente['id'] ?? null) !== $cita['paciente_id']
            || ! is_string($paciente['codigo_paciente'] ?? null) || ! is_string($paciente['nombres'] ?? null)) {
            throw new DependenciaFallidaException('pacientes', 502, 'El servicio de pacientes devolvió una respuesta inválida.');
        }

        $this->registrar('orquestacion.resumen_compuesto', $correlationId, $citaId, $inicio);

        // Solo lo que muestra la web: nada de cédula, teléfono ni dirección.
        return [
            'cita' => [
                'id' => $cita['id'],
                'fecha' => substr((string) $cita['fecha'], 0, 10),
                'hora_inicio' => substr((string) $cita['hora_inicio'], 0, 5),
                'hora_fin' => substr((string) $cita['hora_fin'], 0, 5),
                'estado' => (string) $cita['estado'],
                'descripcion' => $cita['descripcion'] ?? null,
            ],
            'paciente' => [
                'codigo_paciente' => $paciente['codigo_paciente'],
                'nombres' => $paciente['nombres'],
            ],
        ];
    }

    /**
     * Llama a un servicio y devuelve el contenido de "data", o lanza la falla
     * ya traducida al código que debe ver el cliente.
     */
    private function consultar(string $servicio, string $url, string $token, string $correlationId): mixed
    {
        $inicio = hrtime(true);

        try {
            $respuesta = Http::acceptJson()
                ->withToken($token)
                ->withHeaders(['X-Correlation-ID' => $correlationId])
                ->connectTimeout(config('orquestacion.connect_timeout'))
                ->timeout(config('orquestacion.timeout'))
                ->get($url);
        } catch (ConnectionException $e) {
            $this->registrarFalla($servicio, $correlationId, $inicio, null, 'sin conexión o tiempo agotado');

            throw new DependenciaFallidaException($servicio, 503, "El servicio de {$servicio} no está disponible.");
        }

        $this->registrarLlamada($servicio, $correlationId, $inicio, $respuesta);

        return match (true) {
            $respuesta->successful() => $respuesta->json('data'),
            // El token o el permiso valen igual en los dos servicios: se devuelven tal cual.
            in_array($respuesta->status(), [401, 403], true) => throw new DependenciaFallidaException($servicio, $respuesta->status(), 'Acceso denegado por el servicio de '.$servicio.'.'),
            $respuesta->status() === 404 => throw new DependenciaFallidaException($servicio, 404, $servicio === 'citas' ? 'La cita no existe.' : 'El paciente de la cita no existe.'),
            $respuesta->serverError() => throw new DependenciaFallidaException($servicio, 503, "El servicio de {$servicio} no está disponible."),
            default => throw new DependenciaFallidaException($servicio, 502, "El servicio de {$servicio} respondió {$respuesta->status()}."),
        };
    }

    private function registrarLlamada(string $servicio, string $correlationId, int $inicio, Response $respuesta): void
    {
        Log::channel('orquestacion')->info('orquestacion.llamada', [
            'correlation_id' => $correlationId,
            'servicio' => $servicio,
            'status' => $respuesta->status(),
            'instancia' => $respuesta->header('X-Backend-Instance') ?: null,
            'duracion_ms' => (int) round((hrtime(true) - $inicio) / 1e6),
        ]);
    }

    private function registrarFalla(string $servicio, string $correlationId, int $inicio, ?int $status, string $motivo): void
    {
        Log::channel('orquestacion')->warning('orquestacion.falla', [
            'correlation_id' => $correlationId,
            'servicio' => $servicio,
            'status' => $status,
            'motivo' => $motivo,
            'duracion_ms' => (int) round((hrtime(true) - $inicio) / 1e6),
        ]);
    }

    private function registrar(string $evento, string $correlationId, string $citaId, int $inicio): void
    {
        Log::channel('orquestacion')->info($evento, [
            'correlation_id' => $correlationId,
            'cita_id' => $citaId,
            'duracion_ms' => (int) round((hrtime(true) - $inicio) / 1e6),
        ]);
    }
}
