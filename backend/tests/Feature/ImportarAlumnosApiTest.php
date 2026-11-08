<?php

namespace Tests\Feature;

use App\Support\AlumnosExcelArchivo;
use App\Support\AlumnosExcelImport;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// La alta masiva desde Excel. Lo que más importa acá es que nadie cargue dos
// veces al mismo alumno: el padrón real tiene filas sin DNI y esas son las
// únicas que el sistema no puede reconocer por documento.
class ImportarAlumnosApiTest extends ApiTestCase
{
    private function planilla(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $hoja = $ss->getActiveSheet();
        $hoja->setTitle('ALUMNOS');

        foreach (AlumnosExcelImport::COLUMNAS as $i => $col) {
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $col['titulo']);
        }

        $clavePorColumna = array_column(AlumnosExcelImport::COLUMNAS, 'clave');
        foreach ($filas as $n => $fila) {
            foreach ($clavePorColumna as $i => $clave) {
                $hoja->setCellValue(
                    Coordinate::stringFromColumnIndex($i + 1) . ($n + 2),
                    (string) ($fila[$clave] ?? '')
                );
            }
        }

        return UploadedFile::fake()->createWithContent('padron.xlsx', AlumnosExcelArchivo::contenido($ss));
    }

    private function fila(array $datos = []): array
    {
        return array_merge([
            'apellido' => 'Gómez',
            'nombre'   => 'Luisa',
            'dni'      => '35123456',
            'genero'   => 'F',
            'anio'     => 1,
            'division' => '1',
        ], $datos);
    }

    public function test_un_alumno_sin_dni_ya_cargado_no_se_duplica(): void
    {
        $ya = $this->nuevoAlumno([
            'apellido' => 'Cabrera Celestino', 'nombre' => 'Miqueas Valentin',
            'dni' => null, 'anio' => 1, 'division' => '2',
        ]);

        $r = $this->como('admin')->postJson('/api/alumnos/importar/preview', [
            'archivo' => $this->planilla([
                $this->fila(['dni' => '', 'apellido' => 'Cabrera Celestino', 'nombre' => 'Miqueas Valentin', 'division' => '2']),
                $this->fila(['dni' => '45000001', 'apellido' => 'Quiroga', 'nombre' => 'Ana']),
            ]),
        ])->assertOk();

        $r->assertJsonPath('totales.nuevas', 1);
        $r->assertJsonPath('totales.existentes', 1);
        $r->assertJsonPath('filas.0.estado', 'existente');
        $r->assertJsonPath('filas.1.estado', 'nueva');

        $this->como('admin')->postJson('/api/alumnos/importar/aplicar', [
            'archivo' => $this->planilla([
                $this->fila(['dni' => '', 'apellido' => 'Cabrera Celestino', 'nombre' => 'Miqueas Valentin', 'division' => '2']),
                $this->fila(['dni' => '45000001', 'apellido' => 'Quiroga', 'nombre' => 'Ana']),
            ]),
            'confirmar' => '1',
        ])->assertOk()->assertJsonPath('creados', 1);

        // El que no tiene DNI sigue siendo uno solo: sólo se agregó el de DNI nuevo.
        $este = \App\Dominios\Alumnos\Alumno::where('apellido', 'Cabrera Celestino')->get();
        $this->assertCount(1, $este);
        $this->assertSame($ya->id, $este->first()->id);
        $this->assertNull($este->first()->dni);
        $this->assertDatabaseHas('alumnos', ['dni' => '45000001', 'apellido' => 'Quiroga']);
    }

    public function test_dos_filas_sin_dni_con_el_mismo_nombre_en_la_misma_division_se_cargan_una_vez(): void
    {
        $this->como('admin')->postJson('/api/alumnos/importar/aplicar', [
            'archivo' => $this->planilla([
                $this->fila(['dni' => '', 'apellido' => 'Heredia', 'nombre' => 'Sofia Nazareth', 'division' => '2']),
                $this->fila(['dni' => '', 'apellido' => 'HEREDIA', 'nombre' => 'sofia nazareth', 'division' => '2']),
            ]),
            'confirmar' => '1',
        ])->assertOk()->assertJsonPath('creados', 1);

        $this->assertSame(1, \App\Dominios\Alumnos\Alumno::whereNull('dni')->count());
    }

    public function test_el_mismo_nombre_sin_dni_en_otra_division_si_es_un_alumno_nuevo(): void
    {
        $this->nuevoAlumno([
            'apellido' => 'Cabrera Celestino', 'nombre' => 'Miqueas Valentin',
            'dni' => null, 'anio' => 1, 'division' => '2',
        ]);

        $this->como('admin')->postJson('/api/alumnos/importar/preview', [
            'archivo' => $this->planilla([
                $this->fila(['dni' => '', 'apellido' => 'Cabrera Celestino', 'nombre' => 'Miqueas Valentin', 'anio' => 3, 'division' => '4']),
            ]),
        ])->assertOk()->assertJsonPath('totales.nuevas', 1);
    }

    public function test_dos_alumnos_distintos_sin_dni_en_la_misma_division_se_cargan_los_dos(): void
    {
        $this->como('admin')->postJson('/api/alumnos/importar/aplicar', [
            'archivo' => $this->planilla([
                $this->fila(['dni' => '', 'apellido' => 'Heredia', 'nombre' => 'Sofia', 'division' => '2']),
                $this->fila(['dni' => '', 'apellido' => 'Heredia', 'nombre' => 'Luis', 'division' => '2']),
            ]),
            'confirmar' => '1',
        ])->assertOk()->assertJsonPath('creados', 2);
    }

    // Con DNI manda el DNI: un homónimo con otro documento es otra persona.
    public function test_un_nombre_repetido_con_dni_distinto_no_se_omite(): void
    {
        $this->nuevoAlumno([
            'apellido' => 'Gómez', 'nombre' => 'Luisa', 'dni' => '11111111', 'anio' => 1, 'division' => '1',
        ]);

        $this->como('admin')->postJson('/api/alumnos/importar/preview', [
            'archivo' => $this->planilla([
                $this->fila(['dni' => '22222222', 'apellido' => 'Gómez', 'nombre' => 'Luisa']),
            ]),
        ])->assertOk()->assertJsonPath('totales.nuevas', 1);
    }
}
