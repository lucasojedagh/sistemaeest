<?php

namespace App\Support;

/**
 * Headers para que el navegador baje un archivo con nombre legible.
 *
 * El nombre va dos veces: filename= con sólo ASCII (los acentos y la ñ se
 * dañan en algunos clientes y en Windows terminan con "?") y filename*= con el
 * nombre tal cual, que es el que usan los navegadores de hoy.
 */
class Descarga
{
    /** Headers de descarga para un .xlsx. */
    public static function xlsx(string $nombre): array
    {
        return [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . self::ascii($nombre)
                . '"; filename*=UTF-8\'\'' . rawurlencode($nombre),
            'Cache-Control'       => 'no-store',
        ];
    }

    /** Nombre sin acentos ni caracteres raros, para el header viejo. */
    private static function ascii(string $nombre): string
    {
        // iconv translitera los acentos con "?" (ilegal en un nombre de archivo),
        // así que el reemplazo se hace a mano con las vocales acentuadas y la ñ.
        $traducciones = [
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ];
        $sin = preg_replace('/\.xlsx$/i', '', strtr($nombre, $traducciones));
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sin);

        return trim($ascii, '-.') . '.xlsx';
    }
}
