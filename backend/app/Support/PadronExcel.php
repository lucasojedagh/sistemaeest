<?php

namespace App\Support;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Padrón de alumnos en Excel (.xlsx), para la pantalla de Alumnos.
 *
 * Se arma del lado del servidor con PhpSpreadsheet, que ya se usa para las
 * planillas de calificaciones; el frontend no tiene ninguna librería de Excel.
 *
 * Dos hojas:
 *  - PADRÓN: una fila por alumno con todos los datos que ve la pantalla,
 *    encabezado institucional, autofiltro, fila de rótulos congelada y lista
 *    para imprimir (apaisada, con los rótulos repetidos en cada hoja).
 *  - RESUMEN: cantidad por curso, condiciones (PPI, AE, recursan,
 *    intensificación) y los datos que faltan cargar, que es lo que se mira
 *    cuando el padrón se usa para completar expedientes.
 *
 * Las filas llegan ya convertidas a texto/simple desde el controlador: acá sólo
 * se decide el formato, para que la clase no dependa de Eloquent.
 */
class PadronExcel
{
    /** Título, ancho, alineación y clave de cada columna de la hoja PADRÓN. */
    const COLUMNAS = [
        ['N°',                  5,  'centro',    'n'],
        ['Apellido',            22, 'izquierda', 'apellido'],
        ['Nombre',              20, 'izquierda', 'nombre'],
        ['DNI',                 12, 'centro',    'dni'],
        ['N° de matrícula',     15, 'centro',    'matricula'],
        ['Nacionalidad',        16, 'izquierda', 'nacionalidad'],
        ['Sexo',                6,  'centro',    'genero'],
        ['Fecha de nacimiento', 12, 'centro',    'fecha_nacimiento'],
        ['Edad',                6,  'centro',    'edad'],
        ['Año',                 6,  'centro',    'anio'],
        ['División',            9,  'centro',    'division'],
        ['Grupo',               8,  'centro',    'grupo'],
        ['Especialidad',        26, 'izquierda', 'especialidad'],
        ['Estado',              12, 'centro',    'estado'],
        ['PPI',                 6,  'centro',    'ppi'],
        ['AE',                  6,  'centro',    'ae'],
        ['Materias que recursa', 30, 'izquierda', 'recursando'],
        ['Intensificación',     26, 'izquierda', 'intensificacion'],
        ['Teléfono',            16, 'izquierda', 'telefonos'],
        ['Dirección',           30, 'izquierda', 'direccion'],
        ['Correo electrónico',  26, 'izquierda', 'email'],
        ['Responsable/s',       34, 'izquierda', 'responsables'],
        ['Observaciones',       40, 'izquierda', 'observaciones'],
        ['Alta en el sistema',  12, 'centro',    'alta'],
    ];

    /** Fila de la hoja PADRÓN con los rótulos de columna. */
    const FILA_ROTULOS = 7;

    /** Color de la banda alternada y del filete de las tablas. */
    const CELESTE_BANDA = 'FFF2F7FB';
    const CELESTE_BORDE = 'FFBFD3E6';
    const AZUL_ENCABEZADO = 'FF2F5597';

    private Spreadsheet $ss;
    private Worksheet $padron;
    private Worksheet $resumen;

    /** Última columna de la hoja PADRÓN en letras (ej. "W"). */
    private string $colFinal;

    /** Filas de alumno escritas en la hoja PADRÓN. */
    private int $filas = 0;

    /**
     * @param array $alumnos Filas listas para escribir (ver claves en COLUMNAS).
     * @param array $meta    {encabezado_1, encabezado_2, escuela, titulo, ciclo, filtros}
     */
    public function armar(array $alumnos, array $meta): Spreadsheet
    {
        $this->ss = new Spreadsheet();
        $this->ss->removeSheetByIndex(0);

        $this->padron  = $this->ss->createSheet();
        $this->resumen = $this->ss->createSheet();
        $this->padron->setTitle('Padrón');
        $this->resumen->setTitle('Resumen');

        $this->colFinal = Coordinate::stringFromColumnIndex(count(self::COLUMNAS));
        $this->filas    = count($alumnos);

        $this->titulosPadron($alumnos, $meta);
        $this->filaDeRotulos();
        $this->filasAlumnos($alumnos);
        $this->estilosCuerpo(self::FILA_ROTULOS + 1, self::FILA_ROTULOS + max(1, $this->filas));
        $this->vista();
        $this->impresionPadron();

        $this->hojaResumen($alumnos, $meta);

        $this->ss->setActiveSheetIndex(0);
        return $this->ss;
    }

    // ------------------------------------------------------------------
    // Hoja PADRÓN
    // ------------------------------------------------------------------

    /** Encabezado institucional: organismo, escuela, qué se descargó y cuándo. */
    private function titulosPadron(array $alumnos, array $meta): void
    {
        $s = $this->padron;
        $ultima = $this->colFinal;

        $lineas = [
            1 => trim((string) ($meta['encabezado_1'] ?? '')),
            2 => trim((string) ($meta['encabezado_2'] ?? '')),
            3 => trim((string) ($meta['escuela'] ?? '')),
            4 => (string) ($meta['titulo'] ?? 'PADRÓN DE ALUMNOS'),
            5 => $this->lineaDatos(count($alumnos), $meta),
        ];

        foreach ($lineas as $fila => $texto) {
            if ($texto === '') continue;
            $s->mergeCells("A{$fila}:{$ultima}{$fila}");
            $s->setCellValue("A{$fila}", $texto);
            $s->getStyle("A{$fila}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }

        $s->getStyle('A3')->getFont()->setBold(true)->setSize(12);
        $s->getStyle('A4')->getFont()->setBold(true)->setSize(13)
            ->getColor()->setARGB('FF1F4E79');
        $s->getStyle('A5')->getFont()->setItalic(true)->setSize(9);

        $s->getRowDimension(4)->setRowHeight(20);
        $s->getRowDimension(6)->setRowHeight(6);
    }

    /** "42 alumnos · Ciclo lectivo 2026 · Filtros: Año 3° B · Generado el ..." */
    private function lineaDatos(int $total, array $meta): string
    {
        $partes = [number_format($total, 0, ',', '.') . ' alumnos'];
        if (!empty($meta['ciclo'])) $partes[] = 'Ciclo lectivo ' . $meta['ciclo'];
        $filtros = trim((string) ($meta['filtros'] ?? ''));
        $partes[] = $filtros !== '' ? 'Filtros: ' . $filtros : 'Todos los alumnos (sin filtros)';
        $partes[] = 'Generado el ' . date('d/m/Y');
        return implode('  ·  ', $partes);
    }

    /** Renglón de rótulos: negrita blanca sobre celeste, centrado y ajustado. */
    private function filaDeRotulos(): void
    {
        $s = $this->padron;
        $fila = self::FILA_ROTULOS;

        foreach (self::COLUMNAS as $i => $columna) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $s->setCellValue($col . $fila, $columna[0]);
            $s->getColumnDimension($col)->setWidth($columna[1]);
        }

        $s->getStyle("A{$fila}:{$this->colFinal}{$fila}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL_ENCABEZADO]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                             'color' => ['argb' => self::AZUL_ENCABEZADO]]],
        ]);
        $s->getRowDimension($fila)->setRowHeight(30);
    }

    /** Una fila por alumno: acá sólo se escriben los valores. */
    private function filasAlumnos(array $alumnos): void
    {
        $s = $this->padron;
        $fila = self::FILA_ROTULOS;

        foreach ($alumnos as $alumno) {
            $fila++;
            foreach (self::COLUMNAS as $j => $columna) {
                $col = Coordinate::stringFromColumnIndex($j + 1);
                $this->ponerValor($col . $fila, $alumno[$columna[3]] ?? null);
            }
        }

        if ($alumnos === []) {
            $fila++;
            $s->mergeCells("A{$fila}:{$this->colFinal}{$fila}");
            $s->setCellValue("A{$fila}", 'No hay alumnos con los filtros elegidos.');
            $s->getStyle("A{$fila}")->applyFromArray([
                'font'      => ['italic' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
    }

    /**
     * Escribe el valor con su tipo real.
     *
     * Los textos van como cadena explícita: así una observación que empieza con
     * "=" no se convierte en fórmula, y la matrícula conserva los ceros iniciales.
     * Los números van como número, para que el DNI no salga con el triángulo de
     * Excel de "texto con formato de número".
     */
    private function ponerValor(string $celda, $valor): void
    {
        if ($valor === null || $valor === '') return;

        if ($valor instanceof DateTimeInterface) {
            // La fecha viaja como el número que usa Excel (43741 = 3/10/2019) y el
            // formato dd/mm/yyyy se lo pone la función estilosCuerpo a toda la
            // columna. Si se escribiera como texto, Excel mostraría
            // "2019-10-03 00:00:00" y no se podría filtrar por año ni ordenar.
            $serial = Date::PHPToExcel($valor);
            if ($serial === false) return;
            $this->padron->setCellValueExplicit($celda, (string) $serial, DataType::TYPE_NUMERIC);
            return;
        }
        if (is_int($valor) || is_float($valor)) {
            $this->padron->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_NUMERIC);
            return;
        }
        if (is_bool($valor)) $valor = $valor ? 'Sí' : '';

        $this->padron->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
    }

    /**
     * Formato del cuerpo del padrón.
     *
     * El cuerpo tiene sólo cuatro estilos distintos (texto, centrado, con ajuste
     * y fecha), así que cada estilo se arma una vez y se copia el índice a las
     * celdas de su columna. Aplicar el array directamente sobre los rangos era
     * nueve veces más lento: PhpSpreadsheet recorre las celdas dos veces y
     * recomputa el hash del estilo en cada una. Con 1.200 alumnos el formateo
     * pasó de 2,1 s a 0,2 s; celda por celda se iba a 71 segundos.
     */
    private function estilosCuerpo(int $primera, int $ultima): void
    {
        $s = $this->padron;

        $base = [
            'font'    => ['size' => 10],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR,
                                           'color' => ['argb' => self::CELESTE_BORDE]]],
        ];

        $estilos = [
            'texto'  => $base + ['alignment' => ['vertical' => Alignment::VERTICAL_CENTER]],
            'centro' => $base + ['alignment' => ['vertical' => Alignment::VERTICAL_CENTER,
                                                 'horizontal' => Alignment::HORIZONTAL_CENTER]],
            // Ajuste de texto + arriba: las materias recursadas y los responsables
            // van en varias líneas dentro de la misma celda.
            'ajuste' => $base + ['alignment' => ['vertical' => Alignment::VERTICAL_TOP,
                                                 'wrapText' => true]],
            'fecha'  => $base + ['alignment' => ['vertical' => Alignment::VERTICAL_CENTER,
                                                 'horizontal' => Alignment::HORIZONTAL_CENTER],
                                 'numberFormat' => ['formatCode' => NumberFormat::FORMAT_DATE_DDMMYYYY]],
        ];

        // Columnas que llevan el texto ajustado y las dos que son fechas.
        $conAjuste = ['recursando', 'intensificacion', 'observaciones',
                      'responsables', 'direccion', 'especialidad'];
        $sonFecha  = ['fecha_nacimiento', 'alta'];

        $indices = [];
        foreach ($estilos as $perfil => $definicion) {
            $estilo = new Style();
            $estilo->applyFromArray($definicion);
            $this->ss->addCellXf($estilo);
            $indices[$perfil] = $estilo->getIndex();
        }

        foreach (self::COLUMNAS as $j => $columna) {
            $col = Coordinate::stringFromColumnIndex($j + 1);
            $perfil = in_array($columna[3], $sonFecha, true) ? 'fecha'
                : (in_array($columna[3], $conAjuste, true) ? 'ajuste'
                    : ($columna[2] === 'centro' ? 'centro' : 'texto'));

            for ($fila = $primera; $fila <= $ultima; $fila++) {
                $s->getCell($col . $fila)->setXfIndex($indices[$perfil]);
            }
        }

        // Banda celeste muy clara en cada fila par: con 1200 alumnos hace fácil
        // seguir el apellido con la vista. Se pinta con formato condicional sobre
        // el rango completo en lugar de rellenar celda por celda las filas pares.
        $banda = new Conditional();
        $banda->setConditionType(Conditional::CONDITION_EXPRESSION)
            ->setConditions('MOD(ROW(),2)=0');
        $banda->getStyle()->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::CELESTE_BANDA);

        $s->setConditionalStyles("A{$primera}:{$this->colFinal}{$ultima}", [$banda]);
    }

    /** Autofiltro y pán congelado: los rótulos quedan visibles al bajar. */
    private function vista(): void
    {
        $s = $this->padron;
        $ultimaFila = self::FILA_ROTULOS + max(1, $this->filas);

        $s->setAutoFilter('A' . self::FILA_ROTULOS . ':' . $this->colFinal . $ultimaFila);
        $s->freezePane('A' . (self::FILA_ROTULOS + 1));
        $s->setShowGridlines(false);
    }

    /** Impresión: apaisado A4, ajustado al ancho y rótulos repetidos por hoja. */
    private function impresionPadron(): void
    {
        $s = $this->padron;
        $ultimaFila = self::FILA_ROTULOS + max(1, $this->filas);

        $p = $s->getPageSetup();
        $p->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $p->setPaperSize(PageSetup::PAPERSIZE_A4);
        $p->setFitToPage(true);
        $p->setFitToWidth(1);
        $p->setFitToHeight(0);
        $p->setHorizontalCentered(true);
        $p->setRowsToRepeatAtTopByStartAndEnd(self::FILA_ROTULOS, self::FILA_ROTULOS);
        $p->setPrintArea('A1:' . $this->colFinal . $ultimaFila);

        $s->getPageMargins()
            ->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4)
            ->setHeader(0.2)->setFooter(0.2);

        $s->getHeaderFooter()->setEvenFooter('&CPágina &P de &N');
        $s->setSelectedCell('A1');
    }

    // ------------------------------------------------------------------
    // Hoja RESUMEN
    // ------------------------------------------------------------------
    private function hojaResumen(array $alumnos, array $meta): void
    {
        $s = $this->resumen;
        $s->getColumnDimension('A')->setWidth(34);
        foreach (['B', 'C', 'D', 'E', 'F'] as $col) {
            $s->getColumnDimension($col)->setWidth(11);
        }
        $s->setShowGridlines(false);

        $s->mergeCells('A1:F1');
        $s->setCellValue('A1', 'RESUMEN DEL PADRÓN');
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FF1F4E79');
        $s->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $s->mergeCells('A2:F2');
        $s->setCellValue('A2', $this->lineaDatos(count($alumnos), $meta));
        $s->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
        $s->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $fila = 4;
        $fila = $this->bloquePorCurso($alumnos, $fila);
        $fila = $this->bloqueCondiciones($alumnos, $fila + 1);
        $this->bloqueNacionalidades($alumnos, $fila + 1);

        $s->setSelectedCell('A1');
    }

    /** Renglón celeste de título de sección; devuelve la fila de la tabla. */
    private function tituloSeccion(int $fila, string $titulo): int
    {
        $s = $this->resumen;
        $s->mergeCells("A{$fila}:F{$fila}");
        $s->setCellValue("A{$fila}", $titulo);
        $estilo = $s->getStyle("A{$fila}:F{$fila}");
        $estilo->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::AZUL_ENCABEZADO);
        return $fila + 1;
    }

    /** Cuántos alumnos hay por año y división, y cuántos son F / M / O. */
    private function bloquePorCurso(array $alumnos, int $fila): int
    {
        $s = $this->resumen;
        $cabecera = $this->tituloSeccion($fila, 'Alumnos por curso');

        foreach (['Curso', 'Total', 'F', 'M', 'O', 'Egresados'] as $i => $rotulo) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $s->setCellValue($col . $cabecera, $rotulo);
            $estilo = $s->getStyle($col . $cabecera);
            $estilo->getFont()->setBold(true);
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFDCE6F1');
            $estilo->getAlignment()->setHorizontal(
                $i === 0 ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_CENTER
            );
        }

        $grupos = [];
        foreach ($alumnos as $a) {
            $anio = (int) ($a['anio'] ?? 0);
            $div  = strtoupper(trim((string) ($a['division'] ?? '')));
            $clave = sprintf('%02d|%s', $anio, $div);
            $grupos[$clave] ??= [
                'rotulo' => $anio ? trim($anio . '° ' . $div) : 'Sin curso',
                'total' => 0, 'F' => 0, 'M' => 0, 'O' => 0, 'egresados' => 0,
            ];
            $g = &$grupos[$clave];
            $g['total']++;
            $genero = (string) ($a['genero'] ?? '');
            if (isset($g[$genero])) $g[$genero]++;
            if (strcasecmp((string) ($a['estado'] ?? ''), 'Egresado') === 0) $g['egresados']++;
            unset($g);
        }
        ksort($grupos);

        $fila = $cabecera + 1;
        $totales = ['total' => 0, 'F' => 0, 'M' => 0, 'O' => 0, 'egresados' => 0];
        foreach ($grupos as $g) {
            $s->setCellValue('A' . $fila, $g['rotulo']);
            $fila = $this->numerosFila($fila, $g, $totales, $cabecera);
        }

        $s->setCellValue('A' . $fila, 'TOTAL');
        $s->getStyle('A' . $fila)->getFont()->setBold(true);
        foreach (['B' => 'total', 'C' => 'F', 'D' => 'M', 'E' => 'O', 'F' => 'egresados'] as $col => $clave) {
            $s->setCellValue($col . $fila, (int) $totales[$clave]);
            $estilo = $s->getStyle($col . $fila);
            $estilo->getFont()->setBold(true);
            $estilo->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $this->bordesTabla($fila, $cabecera);

        return $fila;
    }

    /** Escribe B..F de una fila del resumen y acumula en $totales. */
    private function numerosFila(int $fila, array $datos, array &$totales, int $cabecera): int
    {
        $s = $this->resumen;
        foreach (['B' => 'total', 'C' => 'F', 'D' => 'M', 'E' => 'O', 'F' => 'egresados'] as $col => $clave) {
            $valor = (int) ($datos[$clave] ?? 0);
            $s->setCellValue($col . $fila, $valor);
            $s->getStyle($col . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if ($fila > $cabecera) $totales[$clave] += $valor;
        }
        return $fila + 1;
    }

    /** Condiciones especiales del padrón descargado. */
    private function bloqueCondiciones(array $alumnos, int $fila): int
    {
        $hay = ['ppi' => 0, 'ae' => 0, 'recursan' => 0, 'intensifican' => 0, 'falta_dni' => 0];
        foreach ($alumnos as $a) {
            if (!empty($a['tiene_ppi']))                     $hay['ppi']++;
            if (!empty($a['tiene_ae']))                      $hay['ae']++;
            if (trim((string) $a['recursando']) !== '')      $hay['recursan']++;
            if (trim((string) $a['intensificacion']) !== '') $hay['intensifican']++;
            if (trim((string) $a['dni']) === '')             $hay['falta_dni']++;
        }

        $cabecera = $this->tituloSeccion($fila, 'Condiciones del padrón');
        $fila = $cabecera;
        $fila = $this->parEtiquetaValor($fila, 'Con PPI (proyecto de inclusión)', $hay['ppi']);
        $fila = $this->parEtiquetaValor($fila, 'Con acompañante externo (AE)', $hay['ae']);
        $fila = $this->parEtiquetaValor($fila, 'Que recursan materias', $hay['recursan']);
        $fila = $this->parEtiquetaValor($fila, 'Con espacios de intensificación', $hay['intensifican']);
        $fila = $this->parEtiquetaValor($fila, 'Sin DNI cargado', $hay['falta_dni']);
        $this->bordesTabla($fila - 1, $cabecera);

        return $fila;
    }

    /**
     * Nacionalidades: sirve para ver de un vistazo cuántos alumnos son extranjeros
     * y de qué país, que es lo que se pide en las notas de convivencia.
     */
    private function bloqueNacionalidades(array $alumnos, int $fila): void
    {
        $conteo = [];
        $sin_dato = 0;
        foreach ($alumnos as $a) {
            $pais = trim((string) $a['nacionalidad']);
            if ($pais === '') { $sin_dato++; continue; }
            $pais = mb_convert_case($pais, MB_CASE_TITLE, 'UTF-8');
            $conteo[$pais] = ($conteo[$pais] ?? 0) + 1;
        }
        arsort($conteo);

        $cabecera = $this->tituloSeccion($fila, 'Nacionalidades');
        $fila = $cabecera;
        if ($conteo === []) {
            $fila = $this->parEtiquetaValor($fila, 'Sin nacionalidad cargada', $sin_dato);
        } else {
            foreach ($conteo as $pais => $cantidad) {
                $fila = $this->parEtiquetaValor($fila, $pais, $cantidad);
            }
            if ($sin_dato > 0) {
                $fila = $this->parEtiquetaValor($fila, 'Sin nacionalidad cargada', $sin_dato);
            }
        }
        $this->bordesTabla($fila - 1, $cabecera);
    }

    /** Rótulo en A y número centrado en B; devuelve la fila siguiente. */
    private function parEtiquetaValor(int $fila, string $rotulo, int $valor): int
    {
        $s = $this->resumen;
        $s->setCellValue('A' . $fila, $rotulo);
        $s->setCellValue('B' . $fila, $valor);
        $s->getStyle('B' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        return $fila + 1;
    }

    /** Filete alrededor de una tabla de la hoja RESUMEN. */
    private function bordesTabla(int $ultimaFila, int $primeraFila): void
    {
        if ($ultimaFila < $primeraFila) return;
        $this->resumen->getStyle("A{$primeraFila}:F{$ultimaFila}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR)
            ->getColor()->setARGB(self::CELESTE_BORDE);
    }

    // ------------------------------------------------------------------
    /**
     * Contenido del .xlsx ya generado, listo para la respuesta HTTP.
     * Se escribe una sola vez y se queda en memoria: el cuerpo y el
     * Content-Length tienen que medir exactamente lo mismo o la descarga
     * llega cortada.
     */
    public function contenido(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'padron_');
        (new Xlsx($this->ss))->save($tmp);
        $contenido = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $contenido;
    }
}
