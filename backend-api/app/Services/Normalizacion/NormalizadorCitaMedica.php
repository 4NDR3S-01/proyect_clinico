<?php

namespace App\Services\Normalizacion;

use App\Exceptions\Normalizacion\EntradaCitaInvalidaException;
use InvalidArgumentException;

/**
 * Traductor / normalizador de solicitudes de cita médica.
 * Práctica individual 2.3 (IS-803), William Cabrera.
 *
 * Convierte dos estructuras heterogéneas al contrato común que ya acepta
 * POST /api/agenda/cita-medica (ver ContratoCitaMedica), sin modificar
 * a los componentes de origen ni al contrato del backend:
 *
 *  - Origen A "web":   formulario de recepción del frontend web (camelCase en
 *                      español, fecha dd/mm/aaaa, horas de 24 h sin cero inicial).
 *  - Origen B "movil": app móvil (inglés, estructura anidada, código numérico,
 *                      hora de 12 h con AM/PM y duración en minutos en vez de hora fin).
 *
 * Nunca convierte en silencio un valor inválido: acumula los errores por campo
 * y lanza EntradaCitaInvalidaException con todos ellos.
 */
final class NormalizadorCitaMedica
{
    public const ORIGEN_WEB = 'web';

    public const ORIGEN_MOVIL = 'movil';

    public const ORIGENES = [self::ORIGEN_WEB, self::ORIGEN_MOVIL];

    private const CAMPOS_WEB = ['codigoPaciente', 'fechaCita', 'horaInicio', 'horaFin', 'motivo'];

    private const CAMPOS_MOVIL = ['patient', 'appointment'];

    private const CAMPOS_MOVIL_PACIENTE = ['code'];

    private const CAMPOS_MOVIL_CITA = ['date', 'startTime', 'durationMinutes', 'notes'];

    private const DURACION_MINIMA = 5;

    private const DURACION_MAXIMA = 480;

    private const MINUTOS_DIA = 24 * 60;

    private const LARGO_CODIGO = 3;

    private const MAX_DESCRIPCION = 1000;

    /**
     * Punto de entrada único: elige la regla de traducción según el origen.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{codigo_paciente: string, fecha: string, hora_inicio: string, hora_fin: string, descripcion: ?string}
     */
    public function normalizar(string $origen, array $entrada): array
    {
        return match ($origen) {
            self::ORIGEN_WEB => $this->desdeFormularioWeb($entrada),
            self::ORIGEN_MOVIL => $this->desdeAppMovil($entrada),
            default => throw new InvalidArgumentException("Origen de datos no soportado: {$origen}."),
        };
    }

    /**
     * Origen A: formulario web de recepción.
     * {"codigoPaciente":" 007 ","fechaCita":"20/11/2026","horaInicio":"9:00","horaFin":"9:30","motivo":"Limpieza"}
     *
     * @param  array<string, mixed>  $entrada
     * @return array{codigo_paciente: string, fecha: string, hora_inicio: string, hora_fin: string, descripcion: ?string}
     */
    public function desdeFormularioWeb(array $entrada): array
    {
        $errores = [];
        $this->rechazarCamposDesconocidos($entrada, self::CAMPOS_WEB, '', $errores);

        $codigo = $this->codigoDesdeTexto($entrada['codigoPaciente'] ?? null, 'codigoPaciente', $errores);
        $fecha = $this->fechaDiaMesAnio($entrada['fechaCita'] ?? null, 'fechaCita', $errores);
        $inicio = $this->hora24($entrada['horaInicio'] ?? null, 'horaInicio', $errores);
        $fin = $this->hora24($entrada['horaFin'] ?? null, 'horaFin', $errores);
        $descripcion = $this->descripcion($entrada['motivo'] ?? null, 'motivo', $errores);

        if ($inicio !== null && $fin !== null && $fin <= $inicio) {
            $errores['horaFin'][] = 'La hora de fin debe ser posterior a la hora de inicio.';
        }

        $this->lanzarSiHayErrores(self::ORIGEN_WEB, $errores);

        return $this->contrato($codigo, $fecha, $inicio, $fin, $descripcion);
    }

    /**
     * Origen B: app móvil.
     * {"patient":{"code":7},"appointment":{"date":"2026-11-20","startTime":"09:00 AM","durationMinutes":30,"notes":"Limpieza"}}
     *
     * @param  array<string, mixed>  $entrada
     * @return array{codigo_paciente: string, fecha: string, hora_inicio: string, hora_fin: string, descripcion: ?string}
     */
    public function desdeAppMovil(array $entrada): array
    {
        $errores = [];
        $this->rechazarCamposDesconocidos($entrada, self::CAMPOS_MOVIL, '', $errores);

        $paciente = $this->objeto($entrada, 'patient', $errores);
        $cita = $this->objeto($entrada, 'appointment', $errores);

        $codigo = $fecha = $inicio = $duracion = $descripcion = null;

        if ($paciente !== null) {
            $this->rechazarCamposDesconocidos($paciente, self::CAMPOS_MOVIL_PACIENTE, 'patient.', $errores);
            $codigo = $this->codigoDesdeEntero($paciente['code'] ?? null, 'patient.code', $errores);
        }

        if ($cita !== null) {
            $this->rechazarCamposDesconocidos($cita, self::CAMPOS_MOVIL_CITA, 'appointment.', $errores);
            $fecha = $this->fechaIso($cita['date'] ?? null, 'appointment.date', $errores);
            $inicio = $this->hora12($cita['startTime'] ?? null, 'appointment.startTime', $errores);
            $duracion = $this->duracion($cita['durationMinutes'] ?? null, 'appointment.durationMinutes', $errores);
            $descripcion = $this->descripcion($cita['notes'] ?? null, 'appointment.notes', $errores);
        }

        $fin = null;
        if ($inicio !== null && $duracion !== null) {
            $fin = $inicio + $duracion;
            if ($fin >= self::MINUTOS_DIA) {
                $errores['appointment.durationMinutes'][] = 'La cita debe terminar el mismo día en que empieza.';
            }
        }

        $this->lanzarSiHayErrores(self::ORIGEN_MOVIL, $errores);

        return $this->contrato($codigo, $fecha, $inicio, $fin, $descripcion);
    }

    /**
     * @return array{codigo_paciente: string, fecha: string, hora_inicio: string, hora_fin: string, descripcion: ?string}
     */
    private function contrato(string $codigo, string $fecha, int $inicio, int $fin, ?string $descripcion): array
    {
        return [
            'codigo_paciente' => $codigo,
            'fecha' => $fecha,
            'hora_inicio' => $this->formatoHora($inicio),
            'hora_fin' => $this->formatoHora($fin),
            'descripcion' => $descripcion,
        ];
    }

    // ---------------------------------------------------------------------
    // Reglas de estructura
    // ---------------------------------------------------------------------

    /** @param array<string, list<string>> $errores */
    private function rechazarCamposDesconocidos(array $entrada, array $permitidos, string $prefijo, array &$errores): void
    {
        foreach (array_keys($entrada) as $campo) {
            if (!in_array($campo, $permitidos, true)) {
                $errores[$prefijo.$campo][] = 'Campo no reconocido para este origen; se rechaza para evitar una entrada ambigua.';
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $errores
     * @return array<string, mixed>|null
     */
    private function objeto(array $entrada, string $campo, array &$errores): ?array
    {
        $valor = $entrada[$campo] ?? null;

        if ($valor === null) {
            $errores[$campo][] = 'El campo es obligatorio.';

            return null;
        }

        if (!is_array($valor) || ($valor !== [] && array_is_list($valor))) {
            $errores[$campo][] = 'Se esperaba un objeto JSON.';

            return null;
        }

        return $valor;
    }

    // ---------------------------------------------------------------------
    // Reglas de normalización por campo
    // ---------------------------------------------------------------------

    /** Código como texto ("7", " 007 ") → "007". */
    private function codigoDesdeTexto(mixed $valor, string $campo, array &$errores): ?string
    {
        if (!$this->presente($valor, $campo, $errores)) {
            return null;
        }

        if (!is_string($valor) || !preg_match('/^\d{1,10}$/', trim($valor))) {
            $errores[$campo][] = 'El código de paciente debe contener solo dígitos.';

            return null;
        }

        return $this->codigoCanonico((int) trim($valor), $campo, $errores);
    }

    /** Código numérico (7) → "007". No acepta texto ni decimales. */
    private function codigoDesdeEntero(mixed $valor, string $campo, array &$errores): ?string
    {
        if (!$this->presente($valor, $campo, $errores)) {
            return null;
        }

        if (!is_int($valor)) {
            $errores[$campo][] = 'El código de paciente debe ser un número entero.';

            return null;
        }

        return $this->codigoCanonico($valor, $campo, $errores);
    }

    private function codigoCanonico(int $numero, string $campo, array &$errores): ?string
    {
        if ($numero <= 0) {
            $errores[$campo][] = 'El código de paciente debe ser mayor que cero.';

            return null;
        }

        return str_pad((string) $numero, self::LARGO_CODIGO, '0', STR_PAD_LEFT);
    }

    /** "20/11/2026" → "2026-11-20". */
    private function fechaDiaMesAnio(mixed $valor, string $campo, array &$errores): ?string
    {
        if (!$this->presente($valor, $campo, $errores)) {
            return null;
        }

        if (!is_string($valor) || !preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($valor), $m)) {
            $errores[$campo][] = 'La fecha debe tener el formato dd/mm/aaaa.';

            return null;
        }

        return $this->fechaCanonica((int) $m[3], (int) $m[2], (int) $m[1], $campo, $errores);
    }

    /** "2026-11-20" → "2026-11-20" (verificando que la fecha exista). */
    private function fechaIso(mixed $valor, string $campo, array &$errores): ?string
    {
        if (!$this->presente($valor, $campo, $errores)) {
            return null;
        }

        if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($valor), $m)) {
            $errores[$campo][] = 'La fecha debe tener el formato aaaa-mm-dd.';

            return null;
        }

        return $this->fechaCanonica((int) $m[1], (int) $m[2], (int) $m[3], $campo, $errores);
    }

    private function fechaCanonica(int $anio, int $mes, int $dia, string $campo, array &$errores): ?string
    {
        if (!checkdate($mes, $dia, $anio)) {
            $errores[$campo][] = 'La fecha no existe en el calendario.';

            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /** "9:00" / "09:00" → minutos desde medianoche (540). */
    private function hora24(mixed $valor, string $campo, array &$errores): ?int
    {
        if (!$this->presente($valor, $campo, $errores)) {
            return null;
        }

        if (!is_string($valor) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($valor), $m)) {
            $errores[$campo][] = 'La hora debe estar en formato de 24 horas (HH:mm).';

            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** "09:00 AM" / "2:30 pm" → minutos desde medianoche. */
    private function hora12(mixed $valor, string $campo, array &$errores): ?int
    {
        if (!$this->presente($valor, $campo, $errores)) {
            return null;
        }

        if (!is_string($valor) || !preg_match('/^(0?[1-9]|1[0-2]):([0-5]\d)\s?(AM|PM)$/i', trim($valor), $m)) {
            $errores[$campo][] = 'La hora debe estar en formato de 12 horas con AM/PM (hh:mm AM).';

            return null;
        }

        $hora = (int) $m[1] % 12;
        if (strtoupper($m[3]) === 'PM') {
            $hora += 12;
        }

        return $hora * 60 + (int) $m[2];
    }

    private function duracion(mixed $valor, string $campo, array &$errores): ?int
    {
        if (!$this->presente($valor, $campo, $errores)) {
            return null;
        }

        if (!is_int($valor) || $valor < self::DURACION_MINIMA || $valor > self::DURACION_MAXIMA) {
            $errores[$campo][] = sprintf(
                'La duración debe ser un número entero de minutos entre %d y %d.',
                self::DURACION_MINIMA,
                self::DURACION_MAXIMA
            );

            return null;
        }

        return $valor;
    }

    /** Recorta y colapsa espacios; una cadena vacía se representa como null. */
    private function descripcion(mixed $valor, string $campo, array &$errores): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (!is_string($valor)) {
            $errores[$campo][] = 'La descripción debe ser texto.';

            return null;
        }

        $texto = trim((string) preg_replace('/\s+/u', ' ', $valor));

        if (mb_strlen($texto) > self::MAX_DESCRIPCION) {
            $errores[$campo][] = 'La descripción no puede superar '.self::MAX_DESCRIPCION.' caracteres.';

            return null;
        }

        return $texto === '' ? null : $texto;
    }

    private function presente(mixed $valor, string $campo, array &$errores): bool
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            $errores[$campo][] = 'El campo es obligatorio.';

            return false;
        }

        return true;
    }

    private function formatoHora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /** @param array<string, list<string>> $errores */
    private function lanzarSiHayErrores(string $origen, array $errores): void
    {
        if ($errores !== []) {
            throw new EntradaCitaInvalidaException($origen, $errores);
        }
    }
}
