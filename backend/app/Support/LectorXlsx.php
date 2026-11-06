<?php

namespace App\Support;

use ZipArchive;

/**
 * Lee una hoja de un .xlsx sin cargar el libro entero.
 *
 * El REGISTRO ALUMNADO de la escuela pesa 6 MB y tiene 16 hojas (una por mes de
 * asistencia). PhpSpreadsheet tarda 15 segundos y pasa de los 256 MB de PHP incluso
 * cuando se le filtra una sola hoja, porque recorre celda por celda las demás. Acá
 * se descomprime únicamente el XML de la hoja que interesa, y se toma el valor que
 * Excel dejó guardado en <v>: el resultado de la fórmula, no la fórmula. Eso es lo
 * que permite leer "Datos personales", que es un espejo de "MATRICULA" a fórmulas.
 */
class LectorXlsx
{
    /** @var array<int, array<int, string>> valor por fila y número de columna */
    private array $celdas = [];

    private int $alto = 0;

    private int $ancho = 0;

    /** Cadenas compartidas ya leídas, por archivo. */
    private static array $compartidas = [];

    public function alto(): int
    {
        return $this->alto;
    }

    /** Índice de la última columna que trae algo en la hoja. */
    public function ancho(): int
    {
        return $this->ancho;
    }

    /** Texto de una celda; vacío si la hoja no la tiene. */
    public function texto(int $colIndex, int $fila): string
    {
        return $this->celdas[$fila][$colIndex] ?? '';
    }

    /**
     * Hojas del libro en el orden de las solapas.
     *
     * @return array<int, array{titulo: string, parte: string, oculta: bool}>
     */
    public static function hojas(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            return [];
        }
        $libro = $zip->getFromName('xl/workbook.xml');
        $relaciones = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->close();
        if (!is_string($libro) || !is_string($relaciones)) {
            return [];
        }

        $destinos = [];
        if (preg_match_all('#<Relationship\b([^>]*)>#', $relaciones, $m)) {
            foreach ($m[1] as $attrs) {
                $id = preg_match('/\bId="([^"]+)"/', $attrs, $x) ? $x[1] : '';
                $target = preg_match('/\bTarget="([^"]+)"/', $attrs, $y) ? $y[1] : '';
                if ($id !== '' && $target !== '') $destinos[$id] = $target;
            }
        }

        $hojas = [];
        if (preg_match_all('#<(?:\w+:)?sheet\b([^>]*)>#', $libro, $m)) {
            foreach ($m[1] as $attrs) {
                $titulo = preg_match('/\bname="([^"]*)"/', $attrs, $x) ? html_entity_decode($x[1], ENT_QUOTES, 'UTF-8') : '';
                $id = preg_match('/\b(?:\w+:)?id="([^"]+)"/', $attrs, $y) ? $y[1] : '';
                if ($titulo === '' || !isset($destinos[$id])) continue;
                $hojas[] = [
                    'titulo' => $titulo,
                    'parte' => self::rutaDentroDelZip($destinos[$id]),
                    'oculta' => (bool) preg_match('/\bstate="(hidden|veryHidden)"/', $attrs),
                ];
            }
        }

        return $hojas;
    }

    /** Target del .rels resuelto dentro del zip ("worksheets/sheet3.xml" => "xl/worksheets/sheet3.xml"). */
    private static function rutaDentroDelZip(string $target): string
    {
        $t = str_replace('\\', '/', ltrim($target, '/'));
        if (!str_starts_with($t, 'xl/')) $t = 'xl/' . $t;
        while (str_contains($t, '../')) {
            $t = substr($t, 3);
        }
        return $t;
    }

    /**
     * Lee la hoja pedida.
     *
     * @param int $topeFilas corta la lectura en esa fila (0 = hasta el final),
     *                        que es lo que hace barata la pasada de reconocimiento.
     */
    public static function abrir(string $ruta, string $parte, int $topeFilas = 0): self
    {
        $lector = new self();
        $compartidas = self::cadenasCompartidas($ruta);

        $url = 'zip://' . str_replace('\\', '/', $ruta) . '#' . $parte;
        $xr = new \XMLReader();
        if (!$xr->open($url)) {
            return $lector;
        }

        $fila = 0;
        $col = 0;
        $tipo = '';
        while ($xr->read()) {
            if ($xr->nodeType === \XMLReader::ELEMENT) {
                if ($xr->name === 'row') {
                    $fila = (int) $xr->getAttribute('r');
                    if ($topeFilas > 0 && $fila > $topeFilas) break;
                } elseif ($xr->name === 'c') {
                    $r = (string) $xr->getAttribute('r');
                    $col = self::columnaDe($r);
                    $tipo = (string) $xr->getAttribute('t');
                    if ($col === 0 || $fila === 0) $col = 0;
                    if ($xr->isEmptyElement && $col !== 0) {
                        $lector->guardar($fila, $col, '');
                        $col = 0;
                    }
                } elseif (($xr->name === 'v' || $xr->name === 't') && $col !== 0) {
                    $texto = trim($xr->readString());
                    if ($tipo === 's') {
                        $texto = $compartidas[(int) $texto] ?? '';
                    } elseif ($tipo === 'b') {
                        $texto = $texto === '1' ? 'Sí' : 'No';
                    }
                    $lector->guardar($fila, $col, $texto);
                    $col = 0;
                }
            }
        }
        $xr->close();

        return $lector;
    }

    /** "BC12" => 55; una referencia sin columna devuelve 0. */
    private static function columnaDe(string $r): int
    {
        if (!preg_match('/^([A-Z]+)/', $r, $m)) return 0;
        $n = 0;
        foreach (str_split($m[1]) as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }
        return $n;
    }

    private function guardar(int $fila, int $col, string $valor): void
    {
        // "53528919.000001" se guarda con decimales sueltos y "12.0" es 12:
        // en una planilla nunca son decimales, es el formato interno de Excel.
        if ($valor !== '' && preg_match('/^-?\d*\.\d+$/', $valor)) {
            $valor = rtrim(rtrim($valor, '0'), '.');
            if ($valor === '' || $valor === '-') $valor = '0';
        }
        if ($valor === '') return;
        $this->celdas[$fila][$col] = $valor;
        if ($fila > $this->alto) $this->alto = $fila;
        if ($col > $this->ancho) $this->ancho = $col;
    }

    /** Cadenas compartidas del libro, leídas una sola vez por archivo. */
    private static function cadenasCompartidas(string $ruta): array
    {
        $clave = realpath($ruta) ?: $ruta;
        if (isset(self::$compartidas[$clave])) {
            return self::$compartidas[$clave];
        }

        $lista = [];
        $xr = new \XMLReader();
        if ($xr->open('zip://' . str_replace('\\', '/', $ruta) . '#xl/sharedStrings.xml')) {
            $i = -1;
            $texto = '';
            $dentro = false;
            while ($xr->read()) {
                if ($xr->nodeType === \XMLReader::ELEMENT && $xr->name === 'si') {
                    $i++; $texto = ''; $dentro = true;
                } elseif ($dentro && $xr->nodeType === \XMLReader::TEXT) {
                    $texto .= $xr->value;
                } elseif ($xr->nodeType === \XMLReader::END_ELEMENT && $xr->name === 'si') {
                    $lista[$i] = $texto;
                    $dentro = false;
                }
            }
            $xr->close();
        }
        // Un libro muy grande no puede dejar las cadenas colgando de la memoria.
        if (count(self::$compartidas) > 4) {
            self::$compartidas = [];
        }

        return self::$compartidas[$clave] = $lista;
    }

    /** Olvida las cadenas leídas (lo llama quien terminó de trabajar con el archivo). */
    public static function olvidar(?string $ruta = null): void
    {
        if ($ruta === null) {
            self::$compartidas = [];
            return;
        }
        unset(self::$compartidas[realpath($ruta) ?: $ruta]);
    }
}
