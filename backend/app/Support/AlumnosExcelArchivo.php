<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Archivos Excel de la alta masiva de alumnos: la plantilla para llenar y el
 * reporte de las filas que no se pudieron importar.
 */
class AlumnosExcelArchivo
{
    private const AZUL = 'FF2F5597';
    private const GRIS_CLARO = 'FFF2F7FB';

    /** Hoja con encabezados y una fila vacia lista para escribir. */
    public static function plantilla(): Spreadsheet
    {
        $ss = new Spreadsheet();
        $hoja = $ss->getActiveSheet();
        $hoja->setTitle('ALUMNOS');

        foreach (AlumnosExcelImport::COLUMNAS as $i => $col) {
            $letra = Coordinate::stringFromColumnIndex($i + 1);
            $hoja->setCellValue($letra . '1', $col['titulo']);
            $hoja->getColumnDimension($letra)->setWidth($col['ancho']);
        }

        $ultima = Coordinate::stringFromColumnIndex(count(AlumnosExcelImport::COLUMNAS));
        $rotulos = $hoja->getStyle('A1:' . $ultima . '1');
        $rotulos->getFont()->setBold(true)->getColor()->setRGB('FFFFFFFF');
        $rotulos->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL);
        $rotulos->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $hoja->getRowDimension(1)->setRowHeight(30);

        $hoja->freezePane('A2');
        $hoja->setAutoFilter('A1:' . $ultima . '1');

        self::comoLlenarlo($ss);

        return $ss;
    }

    /** Reporte con las filas del archivo original y el motivo del rechazo. */
    public static function reporte(array $filas): Spreadsheet
    {
        $ss = new Spreadsheet();
        $hoja = $ss->getActiveSheet();
        $hoja->setTitle('REPORTE');

        $cabeceras = array_merge(['Fila', 'Resultado', 'Motivo'], array_column(AlumnosExcelImport::COLUMNAS, 'titulo'));
        foreach ($cabeceras as $i => $tituloCol) {
            $letra = Coordinate::stringFromColumnIndex($i + 1);
            $hoja->setCellValue($letra . '1', $tituloCol);
            $hoja->getColumnDimension($letra)->setWidth($i < 3 ? ($i === 2 ? 60 : 12) : 18);
        }

        $rotulos = $hoja->getStyle('A1:' . Coordinate::stringFromColumnIndex(count($cabeceras)) . '1');
        $rotulos->getFont()->setBold(true)->getColor()->setRGB('FFFFFFFF');
        $rotulos->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL);

        $fila = 2;
        foreach ($filas as $f) {
            $d = $f['datos'] ?? [];
            $motivos = $f['errores'] ?? [];
            foreach ($f['avisos'] ?? [] as $aviso) {
                $motivos[] = 'Aviso: ' . $aviso;
            }
            $resultado = $f['estado'] === 'rechazada' ? 'Rechazada'
                : ($f['estado'] === 'existente' ? 'Ya estaba' : 'Importada');

            $valores = array_merge([
                $f['fila'] ?? '',
                $resultado,
                implode(' | ', $motivos),
                $d['apellido'] ?? '',
                $d['nombre'] ?? '',
                $d['dni'] ?? '',
                $d['genero'] ?? '',
                $d['fecha_nacimiento'] ?? '',
                $d['nacionalidad'] ?? '',
                $d['matricula'] ?? '',
                $d['email'] ?? '',
                $d['anio'] ?? '',
                $d['division'] ?? '',
                $d['grupo'] ?? '',
                $d['especialidad'] ?? '',
                $d['estado'] ?? '',
                !empty($d['tiene_ppi']) ? 'Sí' : 'No',
                !empty($d['tiene_ae']) ? 'Sí' : 'No',
                $d['telefono'] ?? '',
                $d['direccion'] ?? '',
                $d['responsable']['apellido'] ?? '',
                $d['responsable']['nombre'] ?? '',
                $d['responsable']['parentesco'] ?? '',
                $d['responsable']['telefono'] ?? '',
                $d['observaciones'] ?? '',
            ]);
            foreach ($valores as $i => $v) {
                $letra = Coordinate::stringFromColumnIndex($i + 1);
                $hoja->setCellValueExplicit($letra . $fila, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            if ($resultado === 'Rechazada') {
                $hoja->getStyle('A' . $fila . ':C' . $fila)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFDECEC');
            }
            $fila++;
        }

        $hoja->freezePane('A2');
        $hoja->setAutoFilter('A1:' . Coordinate::stringFromColumnIndex(count($cabeceras)) . max($fila - 1, 1) . '1');
        $hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

        return $ss;
    }

    /** Segunda hoja: instrucciones y valores posibles. */
    private static function comoLlenarlo(Spreadsheet $ss): void
    {
        $hoja = $ss->createSheet();
        $hoja->setTitle('Cómo llenarlo');
        $hoja->getColumnDimension('A')->setWidth(26);
        $hoja->getColumnDimension('B')->setWidth(78);

        $filas = [
            ['Cómo usar esta planilla', ''],
            ['', 'Completá la hoja ALUMNOS: una fila por alumno. Borrá las filas de ejemplo si las dejás, no se importan solas.'],
            ['', 'Guardá el archivo como .xlsx (Excel) o .ods (LibreOffice). Subilo en Alumnos > Importar desde Excel.'],
            ['', 'El sistema primero muestra qué va a pasar y no escribe nada hasta que confirmás.'],
            ['', 'También sirve el "REGISTRO ALUMNADO" del colegio: se reconocen las columnas "APELLIDO Y NOMBRE", "DOCUMENTO TIPO N°", "T.E.L.", "DOMICILIO Y LOCALIDAD" y el curso escrito como "1º Año 1º".'],
            ['', ''],
            ['Columnas obligatorias', 'El nombre (junto o separado) y el año o curso. El DNI no es obligatorio, pero es la forma en que el sistema reconoce al alumno.'],
            ['', ''],
            ['Sexo', 'F / M / O, o la palabra completa: Femenino, Masculino, Otros.'],
            ['Fecha de nacimiento', 'dd/mm/aaaa (por ejemplo 01/03/2008). Queda vacía si no la sabés.'],
            ['DNI', 'Solo números, sin puntos. Es la forma en que el sistema reconoce a un alumno: si el DNI ya existe, la fila se omite y no se pisan sus datos.'],
            ['Correo electrónico', 'El mail del alumno, si lo tiene. Se puede completar después desde la ficha. En el "REGISTRO ALUMNADO" del colegio esta columna a veces trae anotaciones (pases, bajas): eso se guarda en Observaciones y el alumno se carga igual.'],
            ['N° de matrícula', 'Solo el número del legajo (46097, 00231). En el "REGISTRO ALUMNADO" del colegio la columna "Nº DE MATRICULA" suele traer la división del año anterior ("3º Año 6º") o la escuela de origen ("Otra Escuela"): eso no es una matrícula y se guarda en Observaciones, el alumno se carga igual.'],
            ['Año', 'El número del año (1 a ' . SchoolYear::maxYear() . '). También sirve "4°" o "cuarto".'],
            ['División', 'La letra o número de división tal como está activa en el sistema (2, 3, A...). Si no existe para ese año, la fila se rechaza.'],
            ['Grupo', 'A o B (la rotación de la división). Vacío si la división no rota.'],
            ['Especialidad', 'Solo desde 4° año. Podés escribir la clave (informatica, mmo, electromecanica, quimica) o el nombre completo.'],
            ['Estado', 'Activo o Egresado. Si lo dejás vacío se carga como Activo.'],
            ['PPI / AE', 'Sí o No. PPI = Proyecto Pedagógico de Inclusión, AE = Acompañante Externo.'],
            ['Responsable', 'Se carga un solo responsable por alumno (el que figura en la planilla). Después se pueden agregar más desde la ficha.'],
            ['', ''],
            ['Fila de ejemplo', ''],
            ['Gómez', 'Luisa | DNI 35123456 | Sexo F | Nacimiento 01/03/2008 | Nacionalidad Argentina | Año 4 | División 2 | Grupo A | Especialidad Técnico en Informática P. y P. | Estado Activo | PPI No | AE No | Teléfono 3454555555 | Dirección Av. San Martín 1234 | Responsable Gómez, Ana (Madre, 3454566666)'],
        ];

        $fila = 1;
        foreach ($filas as [$a, $b]) {
            $hoja->setCellValue('A' . $fila, $a);
            $hoja->setCellValue('B' . $fila, $b);
            $hoja->getStyle('B' . $fila)->getAlignment()->setWrapText(true);
            if ($a !== '' && $b === '') {
                $hoja->getStyle('A' . $fila)->getFont()->setBold(true);
                $hoja->getStyle('A' . $fila . ':B' . $fila)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS_CLARO);
            }
            $fila++;
        }
    }

    /** Bytes del .xlsx listo para mandar al navegador. */
    public static function contenido(Spreadsheet $ss): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'alumnos_xlsx_');
        (new Xlsx($ss))->save($tmp);
        $contenido = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return $contenido;
    }
}
