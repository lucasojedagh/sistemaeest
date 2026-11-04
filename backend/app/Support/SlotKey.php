<?php

// Utilidad para convertir entre claves de bloque horario (h1..hN) e indices numericos 0-based.

namespace App\Support;

/**
 * Helper para convertir entre:
 *   - slot_key: "hN" (h1, h2, ... h8, etc.)
 *   - índice entero 0-based: 0,1,2,...  (h1 => 0)
 *
 * Notas:
 * - toIdx("h3") => 2
 * - fromIdx(2)  => "h3"
 * - normalize() tolera formatos como: "h3", "3", 3, "bloque_3" y devuelve "hN".
 *   * Si recibe un número (int/float/numérico), se interpreta como ÍNDICE 0-based.
 *   * Si recibe un string con dígitos, usa esos dígitos como número de bloque (1-based).
 */
final class SlotKey
{
    /** "hN" -> índice 0-based (h1 => 0) */
    public static function toIdx(?string $slot): ?int
    {
        if (!$slot) return null;
        if (!preg_match('/^h(\d+)$/i', $slot, $m)) return null;
        $n = (int) $m[1];
        return $n > 0 ? $n - 1 : null;
    }

    /** índice 0-based -> "hN" (0 => h1) */
    public static function fromIdx(?int $idx): ?string
    {
        if ($idx === null) return null;
        if ($idx < 0) return null;
        return 'h' . ($idx + 1);
    }

    /**
     * Normaliza cualquier input a "hN".
     * - Si $value es numérico (int/float o string numérico), se trata como ÍNDICE 0-based.
     *   Ej: 0 -> h1, 1 -> h2, "2" (numérico) -> h3
     * - Si es string con formato "hN", se respeta (y se normaliza a minúsculas).
     * - Si es otro string con dígitos (ej. "bloque_3"), se extraen dígitos y se toma como número 1-based.
     */
    public static function normalize($value): ?string
    {
        if ($value === null || $value === '') return null;

        // 1) numérico => lo interpretamos como ÍNDICE 0-based
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return self::fromIdx((int) $value);
        }

        // 2) "hN"
        $str = (string) $value;
        if (preg_match('/^h(\d+)$/i', $str, $m)) {
            $n = max(1, (int) $m[1]);
            return 'h' . $n;
        }

        // 3) cualquier string con dígitos: usamos esos dígitos como número 1-based
        $n = (int) preg_replace('/\D+/', '', $str);
        return $n > 0 ? ('h' . $n) : null;
    }
}
