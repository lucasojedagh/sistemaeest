<?php

namespace App\Dominios\Alumnos;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\AlumnosExcelArchivo;
use App\Support\AlumnosExcelImport;
use App\Support\Descarga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alta masiva de alumnos desde una planilla Excel.
 *
 * La pantalla siempre hace dos pasos: primero vista previa (no escribe nada) y
 * después la importación. Así nadie carga 1200 alumnos de un clic equivocado.
 * Los alumnos que ya existen se omiten: por DNI, y por nombre y curso cuando el
 * DNI está vacío. La importación nunca pisa datos.
 */
class AlumnoImportadorController extends Controller
{
    // Techo de bytes descomprimidos de una planilla (.xlsx / .ods son zips). Una
    // lista de 1200 alumnos reales ocupa pocos megabytes; el tope sólo corta
    // archivos armados a propósito para reventar la memoria de PHP.
    private const LIMITE_DESCOMPRIMIDO = 300 * 1024 * 1024;

    /** Plantilla vacia con los encabezados y la hoja de instrucciones. */
    public function plantilla()
    {
        $contenido = AlumnosExcelArchivo::contenido(AlumnosExcelArchivo::plantilla());

        return response($contenido, 200, array_merge(
            Descarga::xlsx('plantilla_alumnos.xlsx'),
            ['Content-Length' => strlen($contenido)]
        ));
    }

    /** Qué pasaría con este archivo: nuevas, ya existentes y rechazadas. */
    public function preview(Request $req)
    {
        $resultado = $this->leer($req);

        return [
            'totales' => $resultado['totales'],
            'leyo' => $resultado['total_leido'],
            'errores_generales' => $resultado['errores_generales'],
            'avisos' => $resultado['avisos'] ?? [],
            'filas' => array_map(fn($f) => [
                'fila' => $f['fila'],
                'estado' => $f['estado'],
                'apellido' => $f['apellido'],
                'nombre' => $f['nombre'],
                'dni' => $f['dni'],
                'curso' => $f['curso'],
                'errores' => $f['errores'],
            ], $resultado['filas']),
        ];
    }

    /** Importa solo las filas nuevas, en una unica transaccion. */
    public function aplicar(Request $req)
    {
        if ((string) $req->input('confirmar') !== '1') {
            throw ValidationException::withMessages([
                'archivo' => 'Hay que confirmar la importación antes de escribir.',
            ]);
        }

        $resultado = $this->leer($req);
        if ($resultado['errores_generales'] !== []) {
            return response()->json([
                'message' => implode(' ', $resultado['errores_generales']),
            ], 422);
        }

        $nuevas = array_values(array_filter($resultado['filas'], fn($f) => $f['estado'] === 'nueva'));
        $creados = 0;
        $detalles = [];

        if ($nuevas !== []) {
            DB::transaction(function () use ($nuevas, &$creados, &$detalles) {
                foreach ($nuevas as $f) {
                    $creados += $this->crearAlumno($f['datos']);
                }
            });

            foreach ($nuevas as $f) {
                $detalles[] = $f['apellido'] . ', ' . $f['nombre'];
            }

            ActivityLogger::log(
                $req,
                'Importó ' . $creados . ' alumnos desde Excel',
                'alumno.import',
                'Alumno',
                null,
                null,
                [
                    'Alumnos creados'          => $creados,
                    'Alumnos que ya existían'  => $resultado['totales']['existentes'],
                    'Filas rechazadas'         => $resultado['totales']['rechazadas'],
                    'Primeros creados'         => array_slice($detalles, 0, 10),
                ]
            );
        }

        return [
            'creados' => $creados,
            'totales' => $resultado['totales'],
            'avisos' => $resultado['avisos'] ?? [],
            'rechazadas' => array_values(array_map(fn($f) => [
                'fila' => $f['fila'],
                'alumno' => trim($f['apellido'] . ', ' . $f['nombre']),
                'dni' => $f['dni'],
                'motivos' => $f['errores'],
            ], array_filter($resultado['filas'], fn($f) => $f['estado'] === 'rechazada'))),
            'ejemplos' => array_slice($detalles, 0, 8),
        ];
    }

    /** El mismo archivo, pero devuelto en Excel con el motivo de cada rechazo. */
    public function reporte(Request $req)
    {
        $resultado = $this->leer($req);
        $contenido = AlumnosExcelArchivo::contenido(
            AlumnosExcelArchivo::reporte($resultado['filas'])
        );

        return response($contenido, 200, array_merge(
            Descarga::xlsx('reporte_importacion_alumnos.xlsx'),
            ['Content-Length' => strlen($contenido)]
        ));
    }

    /* ======================= internos ======================= */

    /** Valida el archivo subido y lo traduce a filas clasificadas. */
    private function leer(Request $req): array
    {
        $req->validate([
            'archivo' => ['required', 'file', 'max:20480'],
        ], [
            'archivo.required' => 'Elegí el archivo Excel con la lista de alumnos.',
            'archivo.file' => 'El archivo no se pudo leer. Volvé a elegirlo y probá de nuevo.',
            'archivo.max' => 'El archivo pesa demasiado (máximo 20 MB). Dividí la lista en dos planillas.',
        ]);

        $archivo = $req->file('archivo');
        $extension = strtolower((string) $archivo->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'ods'], true)) {
            throw ValidationException::withMessages([
                'archivo' => 'Tiene que ser un Excel (.xlsx). Guardá la planilla como .xlsx y volvé a subirlo.',
            ]);
        }

        $camino = $archivo->getRealPath();

        // La extensión la escribe quien sube el archivo; lo que dice el contenido
        // es lo que realmente se abre. Sin esto, cualquier otro tipo (un .html
        // guardado como .xlsx, por ejemplo) llegaba hasta PhpSpreadsheet.
        $cabecera = (string) @file_get_contents($camino, false, null, 0, 8);
        $esZip    = str_starts_with($cabecera, 'PK');
        $esViejo  = str_starts_with($cabecera, "\xD0\xCF\x11\xE0");
        if (!(($extension === 'xls' && $esViejo) || ($extension !== 'xls' && $esZip))) {
            throw ValidationException::withMessages([
                'archivo' => 'Ese archivo no es una planilla de Excel aunque termine en .' . $extension . '. Abrila con Excel o LibreOffice, guardala como .xlsx y volvé a subirla.',
            ]);
        }

        $this->descartarZipGigante($camino, $extension);

        try {
            return (new AlumnosExcelImport())->leer($camino);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'archivo' => 'No se pudo leer el archivo. Revisá que sea una planilla válida y que no esté abierta en Excel.',
            ]);
        }
    }

    // Un .xlsx o .ods es un zip: un archivo de unos pocos kilobytes puede
    // descomprimirse en gigabytes y la planilla se arma en memoria. Se mide el
    // tamaño real de las entradas antes de entregarlo a PhpSpreadsheet.
    private function descartarZipGigante(string $camino, string $extension): void
    {
        if ($extension === 'xls') return;

        $zip = new \ZipArchive();
        if ($zip->open($camino) !== true) return; // lo rechaza el lector, con mensaje propio

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            if ($total > self::LIMITE_DESCOMPRIMIDO) break;
        }
        $zip->close();

        if ($total > self::LIMITE_DESCOMPRIMIDO) {
            throw ValidationException::withMessages([
                'archivo' => 'El archivo viene comprimido de una forma que el sistema no puede abrir de forma segura. Volvé a guardarlo desde Excel o LibreOffice como .xlsx y subí esa copia.',
            ]);
        }
    }

    /** Crea un alumno y sus datos sueltos. Devuelve 1 para poder contar. */
    private function crearAlumno(array $d): int
    {
        $alumno = Alumno::create([
            'nombre'           => $d['nombre'],
            'apellido'         => $d['apellido'],
            'genero'           => $d['genero'],
            'nacionalidad'     => $d['nacionalidad'],
            'dni'              => $d['dni'],
            'matricula'        => $d['matricula'],
            'email'            => $d['email'] ?? null,
            'fecha_nacimiento' => $d['fecha_nacimiento'],
            'anio'             => $d['anio'],
            'division'         => $d['division'],
            'grupo'            => $d['grupo'],
            'estado'           => $d['estado'] ?? 'Activo',
            'especialidad'     => $d['especialidad'],
            'observaciones'    => $d['observaciones'],
            'tiene_ppi'        => $d['tiene_ppi'],
            'tiene_ae'         => $d['tiene_ae'],
        ]);

        if (!empty($d['direccion'])) {
            $alumno->direcciones()->create(['titular' => 'Alumno', 'direccion' => $d['direccion']]);
        }
        if (!empty($d['telefono'])) {
            $alumno->telefonos()->create(['titular' => 'Alumno', 'telefono' => $d['telefono']]);
        }

        $r = $d['responsable'] ?? [];
        if (trim((string) ($r['apellido'] ?? '')) !== '' || trim((string) ($r['nombre'] ?? '')) !== '') {
            $alumno->responsables()->create([
                'nombre'      => trim((string) ($r['nombre'] ?? '')),
                'apellido'    => trim((string) ($r['apellido'] ?? '')),
                'parentesco'  => trim((string) ($r['parentesco'] ?? '')),
                'convive'     => false,
                'tutor_legal' => true,
            ]);
            if (!empty($r['telefono'])) {
                $alumno->telefonos()->create([
                    'titular' => trim(($r['apellido'] ?? '') . ' ' . ($r['nombre'] ?? '')),
                    'telefono' => $r['telefono'],
                ]);
            }
        }

        return 1;
    }
}
