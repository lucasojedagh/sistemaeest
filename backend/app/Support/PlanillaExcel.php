<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Planilla de calificaciones en Excel a partir de la plantilla oficial de la
 * escuela (archivos/plantilla_calificaciones.xlsx).
 *
 * La clase ya NO dibuja la planilla: carga la plantilla y escribe los datos en
 * las celdas que el formulario de papel ya tiene preparadas. Así el archivo que
 * descarga el docente es el mismo formulario de la escuela (fuentes, bordes,
 * logo, leyendas y sobre todo la configuración de impresión), y cualquier cambio
 * que se haga en el .xlsx de la escuela se nota en las descargas sin tocar código.
 *
 * Se genera Excel y no PDF a propósito: en Excel las notas quedan con texto real
 * (no se pixelan al imprimir), se puede corregir a mano lo que falte y se imprime
 * con el mismo tamanho que en el papel.
 */
class PlanillaExcel
{
    /** Columna X, la última de la planilla. */
    const COL_TOTAL = 24;

    /** Geometría de la plantilla: alumnos del 15 al 49 y pie del 50 al 58. */
    const FILA_PRIMERO = 15;
    const FILA_ULTIMO_PLANTILLA = 49;
    const FILA_AVISO = 50;      // "IMPORTANTE: LEER ..."
    const FILA_PIE = 58;        // "CLASES EFECTIVAMENTE DADAS" (cierra el área de impresión)

    /** Renglones de alumno que trae la plantilla. */
    const FILAS_PLANTILLA = self::FILA_ULTIMO_PLANTILLA - self::FILA_PRIMERO + 1;

    /** Clave de cada dato => columna de la planilla oficial (H..X). */
    const COLUMNAS = [
        'p1_1' => 'H', 'p1_2' => 'I', 'p1_3' => 'J', 'p1_4' => 'K',
        'p1_prelim' => 'L', 'p1_cuatri' => 'M',
        'p2_1' => 'N', 'p2_2' => 'O', 'p2_3' => 'P', 'p2_4' => 'Q',
        'p2_prelim' => 'R', 'p2_cuatri' => 'S',
        'int_1c' => 'T', 'int_dic' => 'U', 'int_feb' => 'V', 'int_mab' => 'W',
        'final' => 'X',
    ];

    private Spreadsheet $ss;

    /** Hoja de la planilla (A..X). */
    private Worksheet $s;

    /** Filas de alumno de esta planilla (las pone armar() según la cantidad). */
    private int $filas = self::FILAS_PLANTILLA;

    /** Archivo ya generado (se hace una sola vez). */
    private ?string $contenido = null;

    /**
     * Filas del formulario. La plantilla trae 35 renglones; si el curso tiene más
     * se redondea a múltiplos de 5 para que sobre una fila y ningún alumno quede
     * afuera. Con menos alumnos se imprimen los 35 igual, como en el papel.
     */
    public static function filasPara(int $alumnos): int
    {
        return max(self::FILAS_PLANTILLA, (int) ceil(max($alumnos, 1) / 5) * 5);
    }

    /** Última fila de alumno de esta planilla. */
    private function ultimoAlumno(): int
    {
        return self::FILA_PRIMERO + $this->filas - 1;
    }

    /** Última fila del pie, desplazada si se agregaron renglones de alumno. */
    private function ultimoPie(): int
    {
        return self::FILA_PIE + ($this->filas - self::FILAS_PLANTILLA);
    }

    /**
     * Ruta de la plantilla. Se configura en config/planilla.php ('plantilla_xlsx'),
     * así el formulario de la escuela queda afuera del código y alcanza con
     * reemplazar el .xlsx cuando lo actualicen.
     */
    private static function rutaPlantilla(): string
    {
        $ruta = (string) config('planilla.plantilla_xlsx');
        if (!is_file($ruta)) {
            throw new RuntimeException(
                'No se encontró la plantilla de planillas en: ' . $ruta
                .'. Copiá el archivo oficial a esa ruta o indicá otra con PLANILLA_PLANTILLA_XLSX en el .env.'
            );
        }
        return $ruta;
    }

    /**
     * @param array $alumnos  Filas con: alumno_id, apellido, nombre, celdas (notas ya formateadas)
     * @param array $cfg      Textos del encabezado (config/planilla.php)
     */
    public function armar(
        array $alumnos,
        array $cfg,
        string $materia,
        ?string $profesor,
        int $ciclo,
        int $anio,
        string $division
    ): Spreadsheet {
        $this->ss = IOFactory::load(self::rutaPlantilla());
        $this->s = $this->ss->getSheet(0);
        $this->filas = self::filasPara(count($alumnos));

        $this->renombrarHoja($anio, $division);
        $this->agregarFilasDeAlumnos();
        $this->encabezado($cfg, $materia, $profesor, $ciclo, $anio, $division);
        $this->filasAlumnos($alumnos);
        $this->validaciones();
        $this->configurarPagina();

        $this->s->setSelectedCell('A1');
        return $this->ss;
    }

    /** La solapa del archivo se llama como el curso, como en el libro de la escuela. */
    private function renombrarHoja(int $anio, string $division): void
    {
        $titulo = trim(CourseLabel::anioCorto($anio) . ' ' . CourseLabel::divisionCorta($division));
        if ($titulo !== '') {
            $this->s->setTitle($titulo);
        }
    }

    /**
     * Si el curso tiene más alumnos que renglones la plantilla, inserta los que
     * faltan antes del pie. PhpSpreadsheet baja el pie y corre el área de
     * impresión; las filas nuevas se copian de los renglones de alumno de la
     * plantilla (bordes, fuente, alto y las celdas B:G fusionadas del nombre).
     *
     * Los estilos se copian columna por columna: getStyle('A48:X48') devuelve el
     * estilo de la primera celda, y al duplicar ese estilo se pintaba el mismo
     * borde en toda la fila, borrando los separadores dobles de M, S y T.
     */
    private function agregarFilasDeAlumnos(): void
    {
        $extra = $this->filas - self::FILAS_PLANTILLA;
        if ($extra <= 0) {
            return;
        }

        $s = $this->s;
        $interior = self::FILA_ULTIMO_PLANTILLA - 1;   // renglón del medio del bloque
        $cierre = self::FILA_ULTIMO_PLANTILLA;         // el que cerraba, con el borde de abajo medio
        $columnas = $this->columnas();

        $s->insertNewRowBefore(self::FILA_AVISO, $extra);

        for ($i = 0; $i < $extra; $i++) {
            $fila = self::FILA_AVISO + $i;
            // El último renglón nuevo es el que cierra el bloque; los demás van igual
            // que un renglón del medio.
            $molde = $i === $extra - 1 ? $cierre : $interior;
            foreach ($columnas as $letra) {
                $s->duplicateStyle($s->getStyle($letra . $molde), $letra . $fila);
            }
            $s->getRowDimension($fila)->setRowHeight(21);
            $s->mergeCells("B{$fila}:G{$fila}");
        }

        // El renglón 49 ya no cierra el bloque: queda en el medio y su borde de
        // abajo pasa a ser fino.
        foreach ($columnas as $letra) {
            $s->duplicateStyle($s->getStyle($letra . $interior), $letra . $cierre);
        }
    }

    /** Letras A..X de la planilla. */
    private function columnas(): array
    {
        $letras = [];
        for ($i = 1; $i <= self::COL_TOTAL; $i++) {
            $letras[] = Coordinate::stringFromColumnIndex($i);
        }
        return $letras;
    }

    // ------------------------------------------------------------------
    // Encabezado: sólo se escriben los datos, la plantilla ya tiene el estilo
    // ------------------------------------------------------------------
    private function encabezado(array $cfg, string $materia, ?string $profesor, int $ciclo, int $anio, string $division): void
    {
        $s = $this->s;

        $s->getCell('A1')->setValue((string) ($cfg['encabezado_1'] ?? ''));
        $s->getCell('A2')->setValue((string) ($cfg['encabezado_2'] ?? ''));

        $escuela = trim((string) ($cfg['escuela'] ?? ''));
        $bajada = trim((string) ($cfg['escuela_bajada'] ?? ''));
        $s->getCell('A3')->setValue($bajada !== '' ? $escuela . '  "' . $bajada . '"' : $escuela);

        // Título con el ciclo lectivo, como en el libro de la escuela.
        $s->getCell('D5')->setValue(trim((string) ($cfg['titulo'] ?? 'PLANILLA DE CALIFICACIONES')) . ' ' . $ciclo);

        $s->getCell('V4')->setValue(CourseLabel::anioCorto($anio));
        $s->getCell('V5')->setValue(CourseLabel::divisionCorta($division));

        $s->getCell('B8')->setValue($this->lineaRoto('B8', 'ASIGNATURA:', $materia));
        $s->getCell('B9')->setValue($this->lineaRoto('B9', 'PROFESOR:', $profesor ?? ''));
    }

    /**
     * Rótulo + dato + guiones bajos. La cantidad de guiones se calcula con el
     * largo de la línea que ya trae la plantilla, para que al imprimir la raya
     * termine en el mismo lugar aunque la materia sea larga o corta.
     */
    private function lineaRoto(string $celda, string $rotulo, string $valor): string
    {
        $largoOriginal = mb_strlen((string) $this->s->getCell($celda)->getValue());
        $guiones = max(6, $largoOriginal - mb_strlen($rotulo) - mb_strlen($valor) - 5);
        return $rotulo . '    ' . $valor . ' ' . str_repeat('_', $guiones);
    }

    // ------------------------------------------------------------------
    // Filas de alumnos
    // ------------------------------------------------------------------
    private function filasAlumnos(array $alumnos): void
    {
        $s = $this->s;
        $primera = self::FILA_PRIMERO;
        $ultima = $this->ultimoAlumno();

        for ($i = 0; $i < $this->filas; $i++) {
            $fila = $primera + $i;
            $a = $alumnos[$i] ?? null;

            $s->getCell('A' . $fila)->setValue($i + 1);
            $s->getCell('B' . $fila)->setValue($a ? ($a['apellido'] . ', ' . $a['nombre']) : '');

            foreach (self::COLUMNAS as $clave => $letra) {
                $s->getCell($letra . $fila)->setValue($a['celdas'][$clave] ?? '');
            }
        }

        // Las notas quedan como número con un decimal (7,0 / 9,5) para que el
        // Excel se lea igual que la planilla en pantalla. En las valoraciones
        // preliminares (L y R) va texto (TEA / TEP / TED), así que ahí se deja
        // el formato General de la plantilla.
        $s->getStyle("H{$primera}:X{$ultima}")->getNumberFormat()->setFormatCode('0.0');
        foreach (['L', 'R'] as $letra) {
            $s->getStyle("{$letra}{$primera}:{$letra}{$ultima}")->getNumberFormat()->setFormatCode('General');
        }
    }

    /**
     * Escribe los números de la tabla de resumen (después de armar()).
     * Cada clave es una fila: 'apro' (APROBADOS), 'desa', 'sin', 'total'.
     * Cada valor trae 6 números: 1°C, 2°C, TOTAL, DIC, FEB MAR, MAR ABRIL.
     */
    public function ponerResumen(array $filas): void
    {
        $s = $this->s;
        $inicio = $this->ultimoAlumno() + 3;   // fila de APROBADOS (52 en la plantilla)
        $columnas = ['C', 'D', 'E', 'F', 'G', 'H'];

        foreach (['apro' => 0, 'desa' => 1, 'sin' => 2, 'total' => 3] as $clave => $salto) {
            $valores = $filas[$clave] ?? array_fill(0, 6, 0);
            foreach ($columnas as $i => $col) {
                $s->getCell($col . ($inicio + $salto))->setValue((int) ($valores[$i] ?? 0));
            }
        }
    }

    // ------------------------------------------------------------------
    // Configuración de impresión
    // ------------------------------------------------------------------
    /**
     * La plantilla ya viene en papel Legal (oficio) vertical, escala 86 %,
     * centrada y con su área de impresión. Se vuelven a escribir estos valores por
     * dos motivos: PhpSpreadsheet lee el <pageSetUpPr/> vacío de la plantilla como
     * "ajustar a 1 página" (achataría todo si se agregan filas), y el área de
     * impresión tiene que cerrar por el pie de esta planilla.
     */
    private function configurarPagina(): void
    {
        $s = $this->s;
        $p = $s->getPageSetup();
        $p->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $p->setPaperSize(PageSetup::PAPERSIZE_LEGAL);
        $p->setScale(86);
        // null + update=false para no volver a prender el ajuste a página, que
        // anularía la escala del 86 %.
        $p->setFitToPage(false);
        $p->setFitToWidth(null, false);
        $p->setFitToHeight(null, false);
        $p->setHorizontalCentered(true);
        $p->setVerticalCentered(true);
        $p->setPrintArea('A1:X' . $this->ultimoPie());
        // Que los rótulos de las columnas se repitan si la planilla ocupa más de
        // una hoja (cursos con muchos alumnos).
        $p->setRowsToRepeatAtTopByStartAndEnd(11, 14);

        $s->getPageMargins()
            ->setLeft(0.5905511811023622)    // 1,5 cm
            ->setRight(0.39370078740157477)  // 1 cm
            ->setTop(0.39370078740157477)
            ->setBottom(0.39370078740157477)
            ->setHeader(0)
            ->setFooter(0);
    }

    // ------------------------------------------------------------------
    /**
     * Reglas de carga dentro del Excel.
     *
     * El archivo se puede completar a mano, así que se le pone la misma
     * validación que usa el sistema: en las valoraciones preliminares solo
     * entran TEA / TEP / TED (con lista desplegable) y en el resto de las
     * notas solo números del 1 al 10. Si alguien escribe otra cosa, el Excel
     * lo avisa y no lo deja pasar.
     */
    private function validaciones(): void
    {
        $s = $this->s;
        $primero = self::FILA_PRIMERO;
        $ultimo = $this->ultimoAlumno();

        // Valoraciones preliminares (columnas L y R): solo TEA, TEP o TED.
        $preliminar = $s->getDataValidation('L' . $primero);
        $preliminar->setSqref("L{$primero}:L{$ultimo} R{$primero}:R{$ultimo}");
        $preliminar->setType(DataValidation::TYPE_LIST);
        $preliminar->setFormula1('"TEA,TEP,TED"');
        $preliminar->setAllowBlank(true);
        // OJO: el atributo showDropDown del XML va invertido (showDropDown="0"
        // es lo que MUESTRA la flechita). PhpSpreadsheet ya invierte el valor,
        // asi que aca se pasa true para que la lista desplegable se vea.
        $preliminar->setShowDropDown(true);
        $preliminar->setShowInputMessage(true);
        $preliminar->setPromptTitle('Valoración preliminar');
        $preliminar->setPrompt('Elegí una opción: TEA, TEP o TED.');
        $preliminar->setShowErrorMessage(true);
        $preliminar->setErrorStyle(DataValidation::STYLE_STOP);
        $preliminar->setErrorTitle('Valoración preliminar');
        $preliminar->setError('Acá solo va TEA, TEP o TED. Si no hay valoración, dejá la celda vacía.');

        // Todas las demás notas: números del 1 al 10.
        $notas = $s->getDataValidation('H' . $primero);
        $notas->setSqref(
            "H{$primero}:K{$ultimo} M{$primero}:M{$ultimo} N{$primero}:Q{$ultimo}"
            . " S{$primero}:W{$ultimo} X{$primero}:X{$ultimo}"
        );
        $notas->setType(DataValidation::TYPE_DECIMAL);
        $notas->setOperator(DataValidation::OPERATOR_BETWEEN);
        $notas->setFormula1('1');
        $notas->setFormula2('10');
        $notas->setAllowBlank(true);
        $notas->setShowInputMessage(true);
        $notas->setPromptTitle('Calificación');
        $notas->setPrompt('Número del 1 al 10. Para una nota sin evaluar, dejá la celda vacía.');
        $notas->setShowErrorMessage(true);
        $notas->setErrorStyle(DataValidation::STYLE_STOP);
        $notas->setErrorTitle('Calificación');
        $notas->setError('Tiene que ser un número del 1 al 10 (se permiten decimales). Para una nota sin evaluar, dejá la celda vacía.');
    }

    // ------------------------------------------------------------------
    /** Escribe el archivo en una ruta y devuelve esa misma ruta. */
    public function guardar(string $ruta): string
    {
        (new Xlsx($this->ss))->save($ruta);
        return $ruta;
    }

    /**
     * Devuelve el contenido del .xlsx como texto, listo para la descarga.
     * Se genera una sola vez y se guarda en memoria: si se pidiera dos veces
     * (por ejemplo para el cuerpo y para Content-Length) saldría un archivo
     * distinto cada vez y la descarga arrive cortada.
     */
    public function contenido(): string
    {
        if ($this->contenido !== null) {
            return $this->contenido;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'planilla_');
        (new Xlsx($this->ss))->save($tmp);
        $this->contenido = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $this->contenido;
    }
}
