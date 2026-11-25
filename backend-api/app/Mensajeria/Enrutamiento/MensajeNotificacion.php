<?php

namespace App\Mensajeria\Enrutamiento;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Práctica 3.2 (William Cabrera): contrato del mensaje de notificación de cita.
 *
 *   {
 *     "id": "NOT-0001",                    identifica este mensaje (no se procesa dos veces)
 *     "tipo": "confirmacion",              lo usa el enrutador para elegir el destino
 *     "prioridad": "alta",                 alta | media | baja: orden dentro de la cola
 *     "correlation_id": "demo-32-0001",    opcional: une petición, mensaje, consumidor y bitácora (como en la 3.1)
 *     "datos": { "cita_id": "<uuid>", "canal": "correo" }
 *   }
 *
 * El tipo se valida solo en su forma (texto en minúsculas): un tipo desconocido
 * es un mensaje bien formado que el enrutador manda a no_enrutables, no un error
 * de validación.
 */
final class MensajeNotificacion
{
    /** Prioridad del contrato => prioridad AMQP (0..10). */
    public const PRIORIDADES = ['alta' => 9, 'media' => 5, 'baja' => 1];

    public const CANALES = ['correo', 'sms'];

    private const FORMATO_ID = '/^[A-Za-z0-9._:-]+$/';

    public function __construct(
        public readonly string $id,
        public readonly string $tipo,
        public readonly string $prioridad,
        public readonly string $correlationId,
        public readonly string $citaId,
        public readonly string $canal,
        public readonly CarbonImmutable $ocurridoEn,
    ) {
    }

    public static function reglas(): array
    {
        return [
            'id' => ['required', 'string', 'min:4', 'max:64', 'regex:'.self::FORMATO_ID],
            'tipo' => ['required', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'prioridad' => ['required', 'string', 'in:'.implode(',', array_keys(self::PRIORIDADES))],
            'correlation_id' => ['nullable', 'string', 'min:8', 'max:64', 'regex:'.self::FORMATO_ID],
            'datos' => ['required', 'array'],
            'datos.cita_id' => ['required', 'uuid'],
            'datos.canal' => ['required', 'string', 'in:'.implode(',', self::CANALES)],
            'ocurrido_en' => ['nullable', 'date'],
        ];
    }

    public static function correlationIdValido(?string $valor): bool
    {
        return is_string($valor) && strlen($valor) >= 8 && strlen($valor) <= 64
            && preg_match(self::FORMATO_ID, $valor) === 1;
    }

    /**
     * Valida los campos indispensables antes de enrutar.
     *
     * @throws ValidationException si falta un campo obligatorio o tiene un valor inválido.
     */
    public static function desdeArreglo(array $entrada, ?string $correlationIdPorDefecto = null): self
    {
        $datos = Validator::make($entrada, self::reglas(), [
            'required' => 'El campo :attribute es obligatorio.',
            'in' => 'El campo :attribute debe ser uno de: :values.',
            'uuid' => 'El campo :attribute debe ser un UUID.',
            'array' => 'El campo :attribute debe ser un objeto.',
            'string' => 'El campo :attribute debe ser texto.',
            'min' => 'El campo :attribute debe tener al menos :min caracteres.',
            'max' => 'El campo :attribute admite como máximo :max caracteres.',
            'date' => 'El campo :attribute debe ser una fecha.',
            'regex' => 'El campo :attribute tiene caracteres no permitidos.',
            'tipo.regex' => 'El tipo debe ser texto en minúsculas (por ejemplo confirmacion).',
        ])->validate();

        $correlationId = $datos['correlation_id'] ?? $correlationIdPorDefecto ?? (string) Str::uuid();

        return new self(
            id: $datos['id'],
            tipo: $datos['tipo'],
            prioridad: $datos['prioridad'],
            correlationId: $correlationId,
            citaId: strtolower($datos['datos']['cita_id']),
            canal: $datos['datos']['canal'],
            ocurridoEn: isset($datos['ocurrido_en'])
                ? CarbonImmutable::parse($datos['ocurrido_en'])
                : CarbonImmutable::now(),
        );
    }

    /**
     * El consumidor reconstruye el mensaje desde el cuerpo que entrega RabbitMQ.
     *
     * @throws ValidationException
     */
    public static function desdeJson(string $cuerpo): self
    {
        $datos = json_decode($cuerpo, true, 8);

        if (! is_array($datos)) {
            throw ValidationException::withMessages(['cuerpo' => 'El cuerpo del mensaje no es un objeto JSON.']);
        }

        return self::desdeArreglo($datos);
    }

    public function prioridadAmqp(): int
    {
        return self::PRIORIDADES[$this->prioridad];
    }

    public function aArreglo(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'prioridad' => $this->prioridad,
            'correlation_id' => $this->correlationId,
            'ocurrido_en' => $this->ocurridoEn->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'datos' => [
                'cita_id' => $this->citaId,
                'canal' => $this->canal,
            ],
        ];
    }

    public function aJson(): string
    {
        return json_encode($this->aArreglo(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
