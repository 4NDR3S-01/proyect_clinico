<?php

namespace App\Http\Controllers\Api\Enrutamiento;

use App\Http\Controllers\Controller;
use App\Mensajeria\Enrutamiento\EnrutadorNotificaciones;
use App\Mensajeria\Enrutamiento\FiltroNotificacionCita;
use App\Mensajeria\Enrutamiento\MensajeNotificacion;
use App\Models\MensajeEnrutado;
use App\Services\Enrutamiento\MensajeDuplicadoException;
use App\Services\Enrutamiento\ProcesoEnrutamientoNotificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Práctica 3.2 (William Cabrera): entrada del enrutador de notificaciones.
 *
 *   POST /api/enrutamiento/notificaciones          recibe un mensaje y lo valida, filtra y enruta
 *   GET  /api/enrutamiento/notificaciones/{id}     bitácora del mensaje (qué consumidor lo atendió)
 *   GET  /api/enrutamiento/reglas                  tabla de reglas de filtrado y enrutamiento
 *
 * Códigos: 202 enrutado o no_enrutable (quedó en una cola), 200 filtrado (no
 * se publica), 422 inválido, 409 repetido, 503 fallo de transporte.
 */
class EnrutamientoNotificacionController extends Controller
{
    public const CABECERA = 'X-Correlation-ID';

    public function enrutar(Request $request, ProcesoEnrutamientoNotificacion $proceso): JsonResponse
    {
        $cabecera = $request->header(self::CABECERA);
        $correlationId = MensajeNotificacion::correlationIdValido($cabecera) ? $cabecera : (string) Str::uuid();
        $correlationId = MensajeNotificacion::correlationIdValido($request->input('correlation_id'))
            ? $request->input('correlation_id')
            : $correlationId;

        try {
            $registro = $proceso->procesar($request->all(), $correlationId);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'El mensaje no cumple el contrato; no se enruta.',
                'decision' => MensajeEnrutado::INVALIDO,
                'correlation_id' => $correlationId,
                'errors' => $e->errors(),
            ], 422, [self::CABECERA => $correlationId]);
        } catch (MensajeDuplicadoException $e) {
            return response()->json([
                'message' => 'Este mensaje ya fue procesado; no se vuelve a enrutar.',
                'anterior' => $e->anterior->aRespuesta(),
            ], 409, [self::CABECERA => $correlationId]);
        }

        [$estado, $texto] = match ($registro->decision) {
            MensajeEnrutado::ENRUTADO => [202, "Mensaje enrutado a {$registro->destino}."],
            MensajeEnrutado::NO_ENRUTABLE => [202, 'Tipo no reconocido: el mensaje quedó en no_enrutables para revisión.'],
            MensajeEnrutado::FILTRADO => [200, 'El mensaje no cumple el filtro; no se entrega a ningún consumidor.'],
            default => [503, 'No se pudo entregar el mensaje al broker. Intente nuevamente.'],
        };

        return response()->json(['message' => $texto] + $registro->aRespuesta() + [
            'seguimiento' => url('/api/enrutamiento/notificaciones/'.rawurlencode((string) $registro->mensaje_id)),
        ], $estado, [self::CABECERA => $registro->correlation_id]);
    }

    public function mostrar(string $mensajeId): JsonResponse
    {
        $registros = MensajeEnrutado::where('mensaje_id', $mensajeId)->orderBy('id')->get();

        if ($registros->isEmpty()) {
            return response()->json(['message' => 'No hay registros para ese mensaje.'], 404);
        }

        return response()->json([
            'mensaje_id' => $mensajeId,
            'registros' => $registros->map->aRespuesta()->all(),
        ]);
    }

    public function reglas(EnrutadorNotificaciones $enrutador): JsonResponse
    {
        return response()->json([
            'validacion' => 'Obligatorios: id, tipo, prioridad (alta|media|baja), datos.cita_id (uuid), datos.canal (correo|sms).',
            'filtro' => FiltroNotificacionCita::REGLAS,
            'enrutamiento' => $enrutador->tabla(),
            'exchange' => config('rabbitmq.enrutamiento.exchange'),
        ]);
    }
}
