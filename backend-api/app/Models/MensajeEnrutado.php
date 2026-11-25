<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Práctica 3.2: una fila por mensaje recibido en el enrutador de notificaciones.
 */
class MensajeEnrutado extends Model
{
    public const INVALIDO = 'invalido';

    public const FILTRADO = 'filtrado';

    public const ENRUTADO = 'enrutado';

    public const NO_ENRUTABLE = 'no_enrutable';

    public const ERROR_TRANSPORTE = 'error_transporte';

    /** Decisiones de un mensaje que ya fue aceptado y no debe repetirse. */
    public const DEFINITIVAS = [self::FILTRADO, self::ENRUTADO, self::NO_ENRUTABLE];

    protected $table = 'mensajes_enrutados';

    protected $fillable = [
        'mensaje_id',
        'correlation_id',
        'tipo',
        'prioridad',
        'cita_id',
        'canal',
        'decision',
        'regla',
        'motivo',
        'destino',
        'routing_key',
        'cola',
        'consumido_por',
        'consumido_en',
        'resultado',
    ];

    protected function casts(): array
    {
        return [
            'consumido_en' => 'datetime',
        ];
    }

    public function aRespuesta(): array
    {
        return [
            'mensaje_id' => $this->mensaje_id,
            'correlation_id' => $this->correlation_id,
            'tipo' => $this->tipo,
            'prioridad' => $this->prioridad,
            'decision' => $this->decision,
            'regla' => $this->regla,
            'motivo' => $this->motivo,
            'destino' => $this->destino,
            'routing_key' => $this->routing_key,
            'cola' => $this->cola,
            'consumido_por' => $this->consumido_por,
            'consumido_en' => $this->consumido_en?->toIso8601String(),
            'resultado' => $this->resultado,
            'registrado_en' => $this->created_at?->toIso8601String(),
        ];
    }
}
