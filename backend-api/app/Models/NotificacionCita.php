<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificacionCita extends Model
{
    public const PENDIENTE = 'pendiente';

    public const NO_PUBLICADA = 'no_publicada';

    public const PROCESANDO = 'procesando';

    public const ENVIADA = 'enviada';

    public const DESCARTADA = 'descartada';

    public const FALLIDA = 'fallida';

    protected $table = 'notificaciones_cita';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'message_id',
        'correlation_id',
        'cita_id',
        'canal',
        'modo',
        'estado',
        'intentos',
        'destino',
        'mensaje',
        'detalle',
        'publicada_en',
        'procesada_en',
    ];

    protected function casts(): array
    {
        return [
            'publicada_en' => 'datetime',
            'procesada_en' => 'datetime',
        ];
    }

    /** Fila de la tabla de estados, con horas locales y milisegundos. */
    public function aSeguimiento(): array
    {
        $hora = fn ($fecha) => $fecha?->format('Y-m-d H:i:s.v');

        return [
            'message_id' => $this->message_id,
            'correlation_id' => $this->correlation_id,
            'cita_id' => $this->cita_id,
            'canal' => $this->canal,
            'modo' => $this->modo,
            'estado' => $this->estado,
            'intentos' => $this->intentos,
            'destino' => $this->destino,
            'detalle' => $this->detalle,
            'recibida_en' => $hora($this->created_at),
            'publicada_en' => $hora($this->publicada_en),
            'procesada_en' => $hora($this->procesada_en),
        ];
    }
}
