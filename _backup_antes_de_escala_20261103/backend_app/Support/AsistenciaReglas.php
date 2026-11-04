<?php

namespace App\Support;

class AsistenciaReglas
{
    public const ESTADO_PRESENTE = 'presente';
    public const ESTADO_AUSENTE = 'ausente';
    public const ESTADO_JUSTIFICADO = 'justificado';
    public const ESTADO_TARDE = 'tarde';

    public const ESTADOS = [
        self::ESTADO_PRESENTE,
        self::ESTADO_AUSENTE,
        self::ESTADO_JUSTIFICADO,
        self::ESTADO_TARDE,
    ];

    public const ESTADO_LABELS = [
        self::ESTADO_PRESENTE => 'Presente',
        self::ESTADO_AUSENTE => 'Ausente',
        self::ESTADO_JUSTIFICADO => 'Justificado',
        self::ESTADO_TARDE => 'Tarde',
    ];

    public const CLASE_ESTADOS = ['dictada', 'suspendida'];

    public const J_ESTADO_PENDIENTE = 'pendiente';
    public const J_ESTADO_APROBADA = 'aprobada';
    public const J_ESTADO_RECHAZADA = 'rechazada';

    public const J_ESTADOS = [
        self::J_ESTADO_PENDIENTE,
        self::J_ESTADO_APROBADA,
        self::J_ESTADO_RECHAZADA,
    ];

    public const J_ESTADO_LABELS = [
        self::J_ESTADO_PENDIENTE => 'Pendiente',
        self::J_ESTADO_APROBADA => 'Aprobada',
        self::J_ESTADO_RECHAZADA => 'Rechazada',
    ];

    // Criterio único de cómputo (vale para historial, inasistencias y estadísticas):
    // - "tarde" computa como presencia (llegó a clase).
    // - "justificado" computa como inasistencia a efectos de umbrales
    //   (faltó igual, aunque con motivo). Se informa por separado.
    public static function porcentajeAsistencia(int $presentes, int $tardanzas, int $total): ?float
    {
        if ($total <= 0) {
            return null;
        }
        return round((($presentes + $tardanzas) / $total) * 100, 2);
    }

    public static function porcentajeInasistencia(int $ausentes, int $justificados, int $total): ?float
    {
        if ($total <= 0) {
            return null;
        }
        return round((($ausentes + $justificados) / $total) * 100, 2);
    }
}